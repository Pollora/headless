<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\WordPress\ManifestRoutes;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function manifestFor(): SiteManifest
{
    $front = Origin::fromUrl('https://www.example.com');

    return new SiteManifest(new UrlMapper(Origin::fromUrl('https://cms.example.com'), $front), $front, new NoLanguages);
}

it('registers its route on rest_api_init, public and read-only', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    $routes = new ManifestRoutes(manifestFor());
    $routes->register(new WordPressHooks);

    Functions\expect('register_rest_route')->once()->with('headless/v1', '/site', Mockery::on(static fn (array $args): bool => $args['methods'] === 'GET' && $args['permission_callback'] === '__return_true'));
    $routes->routes();
});

it('answers with the manifest', function (): void {
    Functions\when('rest_ensure_response')->alias(static function (array $data): WP_REST_Response {
        $response = Mockery::mock('WP_REST_Response');
        $response->shouldReceive('get_data')->andReturn($data);

        return $response;
    });
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_bloginfo')->alias(static fn (string $key): string => $key === 'name' ? 'My site' : 'Just another site');
    Functions\when('home_url')->justReturn('https://cms.example.com');
    Functions\when('get_locale')->justReturn('fr_FR');
    Functions\when('wp_timezone_string')->justReturn('Europe/Paris');
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => match ($name) {
        'show_on_front' => 'page',
        'page_on_front' => '7',
        'posts_per_page' => '12',
        default => $default,
    });
    Functions\when('wp_get_nav_menus')->justReturn([]);
    Functions\when('get_nav_menu_locations')->justReturn([]);
    Functions\when('get_registered_nav_menus')->justReturn(['primary' => 'Primary Menu']);
    Functions\when('get_post_types')->justReturn([]);
    Functions\when('get_taxonomies')->justReturn([]);

    $manifest = (new ManifestRoutes(manifestFor()))->show()->get_data();

    expect($manifest['version'])->toBe(1)
        ->and($manifest['site'])->toMatchArray(['id' => 1, 'name' => 'My site', 'url' => 'https://www.example.com', 'wordpress_url' => 'https://cms.example.com', 'locale' => 'fr_FR', 'language' => 'fr', 'timezone' => 'Europe/Paris'])
        ->and($manifest['front_page'])->toBe(['show_on_front' => 'page', 'page_on_front' => 7, 'page_for_posts' => 0, 'posts_per_page' => 12])
        ->and($manifest['locations'])->toBe([['location' => 'primary', 'description' => 'Primary Menu', 'menu' => null]])
        ->and($manifest['languages'])->toBe([])
        ->and($manifest['menus'])->toBe([]);
});

it('is on once a front end is set, and can be switched off', function (): void {
    expect(Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com')->manifest)->toBeTrue()
        ->and(Settings::fromArray(['frontend_url' => 'https://www.example.com', 'manifest' => ['enabled' => false]], 'https://cms.example.com')->manifest)->toBeFalse()
        ->and(Settings::fromArray([], 'https://cms.example.com')->manifest)->toBeFalse();
});
