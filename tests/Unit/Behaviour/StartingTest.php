<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\FormRoutes;
use Pollora\Headless\Infrastructure\WordPress\GraphQl;
use Pollora\Headless\Infrastructure\WordPress\ManifestRoutes;
use Pollora\Headless\Infrastructure\WordPress\MediaCdn;
use Pollora\Headless\Infrastructure\WordPress\MediaRoutes;
use Pollora\Headless\Infrastructure\WordPress\Module;
use Pollora\Headless\Infrastructure\WordPress\PreviewLinks;
use Pollora\Headless\Infrastructure\WordPress\PreviewRoutes;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\SeoField;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/*
 * What the package attaches for the settings it is given.
 */

/** @return list<Module> */
function startedModules(array $config, ?Languages $languages = null): array
{
    Functions\when('wp_salt')->alias(static fn (string $scheme): string => 'salt of '.$scheme);
    Functions\when('wp_upload_dir')->justReturn(['baseurl' => 'https://cms.example.com/wp-content/uploads']);

    return Bootstrap::modules(Settings::fromArray($config + ['frontend_url' => 'https://www.example.com'], 'https://cms.example.com'), $languages);
}

function startedModule(array $modules, string $class): ?Module
{
    return array_values(array_filter($modules, static fn (Module $module): bool => $module instanceof $class))[0] ?? null;
}

it('leaves out exactly the part that is switched off', function (array $config, array $gone): void {
    $all = array_map(get_class(...), startedModules([]));
    $left = array_map(get_class(...), startedModules($config));

    expect(array_values(array_diff($all, $left)))->toBe($gone)->and(array_diff($left, $all))->toBe([]);
})->with([
    'GraphQL' => [['graphql' => ['enabled' => false]], [GraphQl::class]],
    'preview' => [['preview' => ['enabled' => false]], [PreviewLinks::class, PreviewRoutes::class]],
    'manifest' => [['manifest' => ['enabled' => false]], [ManifestRoutes::class]],
    'redirects' => [['redirects' => ['enabled' => false]], [RedirectRoutes::class]],
    'SEO' => [['seo' => ['enabled' => false]], [SeoField::class]],
    'search' => [['search' => ['enabled' => false]], [SearchRoutes::class]],
    'forms' => [['forms' => ['enabled' => false]], [FormRoutes::class]],
    'blocks' => [['blocks' => ['enabled' => false]], [BlocksField::class]],
    'media' => [['media' => ['enabled' => false]], [MediaRoutes::class]],
    'tokens' => [['tokens' => ['enabled' => false]], [TokenAuthentication::class]],
]);

it('attaches none of the front-end parts without a front end', function (): void {
    $classes = array_map(get_class(...), startedModules(['frontend_url' => '']));

    expect(array_intersect($classes, [PreviewLinks::class, PreviewRoutes::class, ManifestRoutes::class, RedirectRoutes::class, SeoField::class, SearchRoutes::class, MediaRoutes::class, BlocksField::class, MediaCdn::class]))->toBe([]);
});

/** The token a preview link carries. */
function startedLinkToken(array $modules): string
{
    return (string) startedModule($modules, PreviewLinks::class)?->link('', new WP_Post(['ID' => 42]));
}

/** What the exchange route says to a token. */
function startedExchange(array $modules, string $token): string
{
    $request = new WP_REST_Request('POST', '/headless/v1/preview/exchange');
    $request->set_param('token', $token);
    $answer = startedModule($modules, PreviewRoutes::class)?->exchange($request);

    return $answer instanceof WP_Error ? $answer->get_error_code() : 'accepted';
}

function startedPreviewSite(): void
{
    $transients = new ArrayObject;
    Functions\when('set_transient')->alias(static function (string $key) use ($transients): bool {
        $transients[$key] = true;

        return true;
    });
    Functions\when('get_transient')->alias(static fn (string $key): bool => isset($transients[$key]));
    Functions\when('delete_transient')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('user_can')->justReturn(true);
    Functions\when('get_permalink')->justReturn('https://www.example.com/a/');
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('add_query_arg')->alias(static fn (array $args): string => $args['token']);
    Functions\when('get_post')->justReturn(new WP_Post(['ID' => 42]));
    Functions\when('get_post_type_object')->alias(static function (): WP_Post_Type {
        $type = Mockery::mock('WP_Post_Type');
        $type->show_in_rest = true;

        return $type;
    });
}

it('signs previews with both salts of the site: another site cannot forge them', function (string $changed): void {
    startedPreviewSite();
    $salts = ['auth' => 'salt of auth', 'secure_auth' => 'salt of secure_auth'];
    $modules = startedModules([]);

    expect(startedExchange($modules, startedLinkToken($modules)))->toBe('accepted');

    Functions\when('wp_salt')->alias(static fn (string $scheme): string => $scheme === $changed ? 'another' : $salts[$scheme]);
    $other = Bootstrap::modules(Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com'));

    expect(startedExchange($modules, startedLinkToken($other)))->toBe('preview_token_invalid');
})->with(['auth', 'secure_auth']);

it('signs previews with the secret of the settings when there is one, whatever the salts', function (): void {
    startedPreviewSite();
    $config = ['preview' => ['secret' => str_repeat('s', 40)]];
    $modules = startedModules($config);
    Functions\when('wp_salt')->justReturn('another');
    $other = Bootstrap::modules(Settings::fromArray($config + ['frontend_url' => 'https://www.example.com'], 'https://cms.example.com'));

    expect(startedExchange($modules, startedLinkToken($other)))->toBe('accepted')
        ->and(startedExchange(startedModules([]), startedLinkToken($other)))->toBe('preview_token_invalid');
});

it('caps a search at fifty results unless a lower ceiling is set', function (?int $ceiling, string $description): void {
    $routes = [];
    Functions\when('register_rest_route')->alias(static function (string $namespace, string $route, array $args) use (&$routes): void {
        $routes = $args;
    });

    startedModule(startedModules($ceiling === null ? [] : ['per_page_max' => $ceiling]), SearchRoutes::class)?->routes();

    expect($routes['args']['per_page']['description'])->toBe($description);
})->with([[null, 'Capped at 50.'], [10, 'Capped at 10.']]);

it('starts at once when the plugins are loaded, and waits for them otherwise', function (int $loaded): void {
    Functions\when('did_action')->justReturn($loaded);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\expect('home_url')->times($loaded > 0 ? 1 : 0)->andReturn('https://cms.example.com');
    Actions\expectAdded('plugins_loaded')->times($loaded > 0 ? 0 : 1);
    Functions\when('wp_salt')->justReturn('salt');

    Bootstrap::fromWordPress();
})->with([0, 1]);

it('starts the modules it builds', function (): void {
    Actions\expectAdded('rest_api_init')->atLeast()->once();

    Bootstrap::boot(Settings::fromArray([], 'https://cms.example.com'), new WordPressHooks);
});
