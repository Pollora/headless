<?php

declare(strict_types=1);

use GraphQL\Utils\SchemaPrinter;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Domain\Schema\OpenApiBuilder;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\WordPress\GraphQl;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\UserMetaTokenStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The GraphQL module against a real WPGraphQL, installed by the CI job: the settings it imposes, its CORS answer
 * and what an API token may run.
 */
function graphQlSite(GraphQlPolicy $policy = new GraphQlPolicy, bool $tokens = false): ?TokenAuthentication
{
    $auth = $tokens ? new TokenAuthentication(new TokenService(new UserMetaTokenStore), fn (): bool => true) : null;

    if ($auth instanceof TokenAuthentication) {
        $auth->register(new WordPressHooks);
    }

    (new GraphQl($policy, new CorsPolicy(['https://www.example.com']), $auth))->register(new WordPressHooks);

    return $auth;
}

/**
 * @return array<string, mixed>
 */
function runGraphQl(string $query): array
{
    return graphql(['query' => $query]);
}

afterEach(function (): void {
    foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if (is_array($function) && is_object($function[0]) && str_starts_with($function[0]::class, 'Pollora\\Headless\\')) {
                    remove_filter($hook, $function, $priority);
                }
            }
        }
    }

    delete_option('graphql_general_settings');
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_AUTHORIZATION']);
    wp_set_current_user(0);
});

it('runs against a real WPGraphQL', function (): void {
    expect(function_exists('graphql'))->toBeTrue();
});

it('imposes its settings over the ones in the database', function (): void {
    update_option('graphql_general_settings', ['public_introspection_enabled' => 'on', 'debug_mode_enabled' => 'on', 'batch_limit' => 50]);
    graphQlSite();

    expect(get_graphql_setting('public_introspection_enabled'))->toBe('off')
        ->and(get_graphql_setting('debug_mode_enabled'))->toBe('off')
        ->and((int) get_graphql_setting('batch_limit'))->toBe(5)
        ->and(get_graphql_setting('graphql_endpoint', 'graphql'))->toBe('graphql');
});

it('closes introspection to visitors and keeps it for a logged-in user', function (): void {
    graphQlSite();
    $query = '{ __schema { queryType { name } } }';

    $visitor = runGraphQl($query);
    wp_set_current_user(1);
    $admin = runGraphQl($query);

    expect($visitor['errors'][0]['message'] ?? '')->toContain('ntrospection')
        ->and($admin['data']['__schema']['queryType']['name'] ?? null)->toBe('RootQuery');
});

it('refuses a query deeper than the limit', function (): void {
    graphQlSite(new GraphQlPolicy(maxDepth: 2));

    $result = runGraphQl('{ posts { nodes { author { node { name } } } } }');

    expect($result['errors'][0]['message'] ?? '')->toContain('depth');
});

it('answers with the CORS policy instead of the wildcard', function (): void {
    graphQlSite();
    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';

    $allowed = apply_filters('graphql_response_headers_to_send', ['Access-Control-Allow-Origin' => '*', 'Vary' => 'X-GraphQL-Preview']);
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.net';
    $refused = apply_filters('graphql_response_headers_to_send', ['Access-Control-Allow-Origin' => '*']);

    expect($allowed['Access-Control-Allow-Origin'])->toBe('https://www.example.com')
        ->and($allowed['Vary'])->toContain('Origin')
        ->and($refused)->not->toHaveKey('Access-Control-Allow-Origin');
});

it('logs an API token in on GraphQL and keeps a read token from running a mutation', function (): void {
    $auth = graphQlSite(tokens: true);
    $service = new TokenService(new UserMetaTokenStore);
    $read = $service->create(1, 'read', [TokenScope::Read], [], null);
    $write = $service->create(1, 'write', [TokenScope::Write], [], null);

    $mutation = 'mutation { createPost(input: { title: "From a token", status: DRAFT }) { post { databaseId } } }';
    $created = [];

    foreach ([$read, $write] as $issued) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$issued->secret;
        wp_set_current_user(0);
        wp_set_current_user((int) $auth->authenticate(false));
        $viewer = runGraphQl('{ viewer { databaseId } }');
        $result = runGraphQl($mutation);
        $created[] = [$viewer['data']['viewer']['databaseId'] ?? null, $result];
    }

    foreach (get_posts(['post_status' => 'draft', 'title' => 'From a token', 'numberposts' => -1]) as $post) {
        wp_delete_post($post->ID, true);
    }
    $service->revoke(1, $read->token->id);
    $service->revoke(1, $write->token->id);

    expect($created[0][0])->toBe(1)
        ->and($created[0][1]['errors'][0]['message'] ?? '')->toContain('not allowed')
        ->and($created[1][1]['data']['createPost']['post']['databaseId'] ?? null)->not->toBeNull();
});

it('describes the real REST API as an OpenAPI document', function (): void {
    $document = (new OpenApiBuilder('Site', '1', rest_url(), ['wp/v2']))->build(rest_get_server()->get_routes());

    expect($document['paths'])->toHaveKey('/wp/v2/posts/{id}')
        ->and($document['paths']['/wp/v2/posts']['get']['parameters'])->not->toBeEmpty()
        ->and($document['paths']['/wp/v2/posts']['post']['requestBody'])->toHaveKey('content')
        ->and(json_encode($document))->toBeString();
});

it('prints the real GraphQL schema as SDL', function (): void {
    $sdl = SchemaPrinter::doPrint(WPGraphQL::get_schema());

    expect($sdl)->toContain('type RootQuery')->and($sdl)->toContain('type Post ');
});
