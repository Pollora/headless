<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use GraphQL\Error\UserError;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\GraphQl;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

// WPGraphQL's error class is not loaded in the unit suite
if (! class_exists('GraphQL\Error\UserError')) {
    eval('namespace GraphQL\Error; class UserError extends \Exception {}');
}

afterEach(function (): void {
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
});

it('imposes the cautious settings by default', function (): void {
    expect((new GraphQlPolicy)->forced())->toBe([
        'public_introspection_enabled' => 'off',
        'debug_mode_enabled' => 'off',
        'batch_queries_enabled' => 'on',
        'batch_limit' => 5,
        'query_depth_enabled' => 'on',
        'query_depth_max' => 15,
    ]);
});

it('leaves alone a setting set to null and turns off batch and depth with 0', function (): void {
    $policy = new GraphQlPolicy(introspection: null, debug: null, batchLimit: 0, maxDepth: 0, graphiql: false, restrictToLoggedIn: true);

    expect($policy->forced())->toBe([
        'batch_queries_enabled' => 'off',
        'query_depth_enabled' => 'off',
        'graphiql_enabled' => 'off',
        'restrict_endpoint_to_logged_in_users' => 'on',
    ])
        ->and((new GraphQlPolicy(null, null, null, null))->forced())->toBe([]);
});

it('resolves a setting only in the general section', function (): void {
    $policy = new GraphQlPolicy;

    expect($policy->resolve('graphql_general_settings', 'public_introspection_enabled', 'on'))->toBe('off')
        ->and($policy->resolve('graphql_general_settings', 'graphql_endpoint', 'api'))->toBe('api')
        ->and($policy->resolve('another_section', 'public_introspection_enabled', 'on'))->toBe('on');
});

it('reads the graphql section of the settings', function (): void {
    $default = Settings::fromArray([], 'https://cms.example.com');
    $custom = Settings::fromArray(['graphql' => ['introspection' => true, 'batch_limit' => '3', 'max_depth' => null, 'graphiql' => false]], 'https://cms.example.com');
    $off = Settings::fromArray(['graphql' => ['enabled' => false]], 'https://cms.example.com');

    expect($default->graphql)->toEqual(new GraphQlPolicy)
        ->and($custom->graphql)->toEqual(new GraphQlPolicy(introspection: true, batchLimit: 3, maxDepth: null, graphiql: false))
        ->and($off->graphql)->toBeNull();
});

it('is one of the modules', function (): void {
    $modules = Bootstrap::modules(Settings::fromArray(['tokens' => ['enabled' => true]], 'https://cms.example.com'));

    expect(array_filter($modules, static fn ($module): bool => $module instanceof GraphQl))->toHaveCount(1);
});

it('hooks the settings, the headers and the guard', function (): void {
    Filters\expectAdded('graphql_get_setting_section_field_value')->once()->with(Mockery::type('array'), 20, 5);
    Filters\expectAdded('graphql_response_headers_to_send')->once()->with(Mockery::type('array'), 20);
    Filters\expectAdded('graphql_pre_resolve_field')->once()->with(Mockery::type('array'), 5, 6);

    (new GraphQl(new GraphQlPolicy, new CorsPolicy(['https://www.example.com']), new TokenAuthentication(new TokenService(new InMemoryTokenStore))))->register(new WordPressHooks);
});

it('hooks only the settings without CORS or tokens', function (): void {
    Filters\expectAdded('graphql_get_setting_section_field_value')->once();
    Filters\expectAdded('graphql_response_headers_to_send')->never();
    Filters\expectAdded('graphql_pre_resolve_field')->never();

    (new GraphQl(new GraphQlPolicy))->register(new WordPressHooks);
});

it('imposes a setting', function (): void {
    $module = new GraphQl(new GraphQlPolicy);

    expect($module->setting('on', 'off', 'public_introspection_enabled', [], 'graphql_general_settings'))->toBe('off');
});

it('replaces the wildcard origin with the policy', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';
    $module = new GraphQl(new GraphQlPolicy, new CorsPolicy(['https://www.example.com']));

    $headers = $module->headers([
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
        'Access-Control-Max-Age' => '600',
        'Content-Type' => 'application/json',
        'Vary' => 'X-GraphQL-Preview',
    ]);

    expect($headers['Access-Control-Allow-Origin'])->toBe('https://www.example.com')
        ->and($headers['Vary'])->toBe('X-GraphQL-Preview, Origin')
        ->and($headers['Content-Type'])->toBe('application/json')
        ->and($headers)->not->toHaveKey('Access-Control-Allow-Headers');
});

it('answers a preflight with the headers a client may send, including the preview one', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';
    $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'content-type, x-graphql-preview';

    $headers = (new GraphQl(new GraphQlPolicy, new CorsPolicy(['https://www.example.com'])))->headers(['Access-Control-Allow-Origin' => '*']);

    expect($headers['Access-Control-Allow-Headers'])->toBe('content-type, x-graphql-preview')
        ->and($headers['Vary'])->toBe('Origin');
});

it('gives no origin to a site that is not listed', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.net';

    $headers = (new GraphQl(new GraphQlPolicy, new CorsPolicy(['https://www.example.com'])))->headers(['Access-Control-Allow-Origin' => '*']);

    expect($headers)->not->toHaveKey('Access-Control-Allow-Origin')->and($headers['Vary'])->toBe('Origin');
});

it('leaves the headers alone without a CORS policy', function (): void {
    expect((new GraphQl(new GraphQlPolicy))->headers(['Access-Control-Allow-Origin' => '*']))->toBe(['Access-Control-Allow-Origin' => '*']);
});

/**
 * @param  list<TokenScope>  $scopes
 * @param  list<string>  $routes
 */
function graphQlGuard(array $scopes, array $routes = []): GraphQl
{
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer hl.7.0123456789ab.'.str_repeat('A', 43);
    $service = new TokenService(new InMemoryTokenStore);
    $auth = new TokenAuthentication($service, fn (): bool => true);

    $token = new Token('0123456789ab', 7, 'next', 'hash', $scopes, $routes, 1);
    (new ReflectionProperty($auth, 'token'))->setValue($auth, $token);

    return new GraphQl(new GraphQlPolicy, null, $auth);
}

it('lets a read token run queries and refuses its mutations', function (): void {
    $guard = graphQlGuard([TokenScope::Read]);

    expect($guard->guard(null, null, [], null, null, 'RootQuery'))->toBeNull();
    $guard->guard(null, null, [], null, null, 'RootMutation');
})->throws(UserError::class);

it('lets a write token run mutations', function (): void {
    expect(graphQlGuard([TokenScope::Write])->guard(null, null, [], null, null, 'RootMutation'))->toBeNull();
});

it('refuses a token limited to other routes', function (): void {
    graphQlGuard([TokenScope::Read], ['/wp/v2'])->guard(null, null, [], null, null, 'RootQuery');
})->throws(UserError::class);

it('accepts a token limited to the graphql route', function (): void {
    expect(graphQlGuard([TokenScope::Read], ['/graphql'])->guard('x', null, [], null, null, 'RootQuery'))->toBe('x');
});

it('ignores fields that are not at the root, and requests without a token', function (): void {
    $withToken = graphQlGuard([TokenScope::Read]);
    $without = new GraphQl(new GraphQlPolicy, null, new TokenAuthentication(new TokenService(new InMemoryTokenStore), fn (): bool => true));

    expect($withToken->guard(null, null, [], null, null, 'Post'))->toBeNull()
        ->and($without->guard(null, null, [], null, null, 'RootMutation'))->toBeNull();
});
