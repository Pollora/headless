<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\Cors;
use Pollora\Headless\Infrastructure\WordPress\FrontLinks;
use Pollora\Headless\Infrastructure\WordPress\ManifestRoutes;
use Pollora\Headless\Infrastructure\WordPress\RestPerformance;
use Pollora\Headless\Infrastructure\WordPress\RestSecurity;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;
use Pollora\Headless\Infrastructure\WordPress\TemplateRedirectGate;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The hooks the package attaches to, against the real ones: a Brain Monkey double cannot tell
 * that a priority is wrong or that a hook no longer exists.
 */
function wordpressSettings(array $config = []): Settings
{
    return Settings::fromArray(['frontend_url' => 'https://www.example.com', ...$config], home_url());
}

afterEach(function (): void {
    // Take back what this package attached, and nothing of WordPress's own
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

    remove_all_filters('wp_redirect');
    unset($_SERVER['REQUEST_URI']);
});

it('redirects a public page to the front end on the real template_redirect', function (): void {
    $captured = [];
    add_filter('wp_redirect', static function (string $location, int $status) use (&$captured): false {
        $captured = [$location, $status];

        return false;
    }, 10, 2);

    $ended = 0;
    (new TemplateRedirectGate(wordpressSettings()->gate, static function () use (&$ended): void {
        $ended++;
    }))->register(new WordPressHooks);

    $_SERVER['REQUEST_URI'] = '/blog/hello?x=1';
    do_action('template_redirect');

    expect($captured)->toBe(['https://www.example.com/blog/hello?x=1', 302])
        ->and($ended)->toBe(1)
        ->and(has_action('template_redirect'))->not->toBeFalse();
});

it('registers the gate after redirect_canonical, which is at priority 10', function (): void {
    expect(has_action('template_redirect', 'redirect_canonical'))->toBe(10);

    Bootstrap::boot(wordpressSettings(), new WordPressHooks);
    $priorities = array_keys($GLOBALS['wp_filter']['template_redirect']->callbacks);

    expect($priorities)->toContain(11)
        ->and(max($priorities))->toBeGreaterThanOrEqual(11);
});

it('lets the REST API and the administration through', function (): void {
    $captured = [];
    add_filter('wp_redirect', static function (string $location) use (&$captured): false {
        $captured[] = $location;

        return false;
    });

    (new TemplateRedirectGate(wordpressSettings()->gate, static function (): void {}))->register(new WordPressHooks);

    foreach (['/wp-json/wp/v2/posts', '/wp-admin/index.php', '/graphql'] as $uri) {
        $_SERVER['REQUEST_URI'] = $uri;
        do_action('template_redirect');
    }

    expect($captured)->toBe([]);
});

it('removes the head items WordPress really adds, with their real priorities', function (): void {
    expect(has_action('wp_head', 'wp_generator'))->not->toBeFalse()
        ->and(has_action('wp_head', 'rsd_link'))->not->toBeFalse()
        ->and(has_action('wp_head', 'print_emoji_detection_script'))->not->toBeFalse();

    Bootstrap::boot(wordpressSettings(), new WordPressHooks);
    do_action('init');

    expect(has_action('wp_head', 'wp_generator'))->toBeFalse()
        ->and(has_action('wp_head', 'rsd_link'))->toBeFalse()
        ->and(has_action('wp_head', 'feed_links'))->toBeFalse()
        ->and(has_action('wp_head', 'print_emoji_detection_script'))->toBeFalse()
        ->and(has_action('wp_head', 'wp_shortlink_wp_head'))->toBeFalse()
        ->and(has_action('wp_head', 'rest_output_link_wp_head'))->toBeFalse()
        ->and(has_action('wp_head', 'wp_oembed_add_discovery_links'))->toBeFalse()
        ->and(has_action('template_redirect', 'rest_output_link_header'))->toBeFalse();
});

it('turns XML-RPC, the core sitemap and the origin robots answers off', function (): void {
    Bootstrap::boot(wordpressSettings(), new WordPressHooks);

    expect(apply_filters('xmlrpc_enabled', true))->toBeFalse()
        ->and(apply_filters('wp_sitemaps_enabled', true))->toBeFalse()
        ->and(apply_filters('wp_headers', []))->toHaveKey('X-Robots-Tag')
        ->and(apply_filters('robots_txt', "User-agent: *\nAllow: /\n", true))->toBe("User-agent: *\nDisallow: /\n");
});

it('describes the real site', function (): void {
    $site = SiteContext::current();
    $report = StatusReport::build(wordpressSettings(), $site, wp_get_theme()->exists(), defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);

    expect($site->siteId)->toBe(get_current_blog_id())
        ->and($site->homeUrl)->toBe(home_url())
        ->and($site->locale)->not->toBe('')
        ->and(wordpressSettings()->mode)->toBe(FrontMode::Redirect)
        ->and($report->rows)->not->toBeEmpty();
});

it('replaces core\'s CORS handler and answers only the listed origin', function (): void {
    $lines = [];
    $cors = new Cors(
        new CorsPolicy(['https://www.example.com']),
        static function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );
    $cors->register(new WordPressHooks);

    // Build the REST server again: core adds its handler on rest_api_init, then the package takes it off
    $GLOBALS['wp_rest_server'] = null;
    $server = rest_get_server();

    expect(has_filter('rest_pre_serve_request', 'rest_send_cors_headers'))->toBeFalse()
        ->and(has_filter('rest_pre_serve_request', [$cors, 'send']))->not->toBeFalse();

    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';
    $request = new WP_REST_Request('GET', '/wp/v2/posts');
    apply_filters('rest_pre_serve_request', false, new WP_REST_Response, $request, $server);

    expect($lines)->toContain('Access-Control-Allow-Origin: https://www.example.com');

    $lines = [];
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.org';
    apply_filters('rest_pre_serve_request', false, new WP_REST_Response, $request, $server);
    unset($_SERVER['HTTP_ORIGIN']);

    expect($lines)->toBe(['Vary: Origin']);
});

it('answers 401 on the users routes to a visitor, and still lists them for an administrator', function (): void {
    (new RestSecurity(true, false))->register(new WordPressHooks);

    wp_set_current_user(0);
    $visitor = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));

    $administrator = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
    wp_set_current_user($administrator?->ID ?? 0);
    $admin = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));
    wp_set_current_user(0);

    expect($visitor->get_status())->toBe(401)
        ->and($visitor->get_data()['code'])->toBe('rest_user_cannot_view')
        ->and($administrator)->not->toBeNull()
        ->and($admin->get_status())->toBe(200);
});

it('leaves the users provider out of the core sitemap', function (): void {
    (new RestSecurity(true, false))->register(new WordPressHooks);

    expect(apply_filters('wp_sitemaps_add_provider', new stdClass, 'users'))->toBeFalse()
        ->and(apply_filters('wp_sitemaps_add_provider', 'posts-provider', 'posts'))->toBe('posts-provider');
});

it('points the permalinks, the REST link and the links of the content to the front end', function (): void {
    $mapper = new UrlMapper(
        Origin::fromUrl(home_url()),
        Origin::fromUrl('https://www.example.com'),
    );
    (new FrontLinks($mapper, new ContentLinks($mapper)))->register(new WordPressHooks);

    $id = wp_insert_post([
        'post_title' => 'Headless link test',
        'post_status' => 'publish',
        'post_content' => '<p><a href="'.home_url('/about/').'">About</a> <a href="'.content_url('/uploads/a.pdf').'">File</a></p>',
    ]);

    $link = get_permalink($id);
    $api = rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts/'.$id));
    $rendered = $api->get_data()['content']['rendered'];
    wp_delete_post($id, true);

    expect($link)->toStartWith('https://www.example.com/')
        ->and($api->get_data()['link'])->toStartWith('https://www.example.com/')
        ->and($rendered)->toContain('href="https://www.example.com/about/"')
        ->and($rendered)->toContain('href="'.content_url('/uploads/a.pdf').'"');
});

it('marks a public REST answer as cacheable, and not a logged-in one', function (): void {
    (new RestPerformance(new RestCachePolicy, null))->register(new WordPressHooks);

    // rest_post_dispatch runs in WP_REST_Server::serve_request(), after dispatch(): apply it as the server does
    $answer = static function (): WP_HTTP_Response {
        $request = new WP_REST_Request('GET', '/wp/v2/posts');

        return apply_filters('rest_post_dispatch', rest_do_request($request), rest_get_server(), $request);
    };

    wp_set_current_user(0);
    $public = $answer();

    $administrator = get_users(['role' => 'administrator', 'number' => 1])[0];
    wp_set_current_user($administrator->ID);
    $private = $answer();
    wp_set_current_user(0);

    expect($public->get_headers()['Cache-Control'] ?? '')->toStartWith('public, max-age=60')
        ->and($private->get_headers())->not->toHaveKey('Cache-Control');
});

it('lowers per_page before WordPress validates it', function (): void {
    (new RestPerformance(null, 3))->register(new WordPressHooks);

    $request = new WP_REST_Request('GET', '/wp/v2/posts');
    $request->set_param('per_page', 100);
    $response = rest_do_request($request);

    expect($response->get_status())->toBe(200)
        ->and(count($response->get_data()))->toBeLessThanOrEqual(3);
});

function manifestRequest(): WP_REST_Response
{
    $mapper = new UrlMapper(Origin::fromUrl(home_url()), Origin::fromUrl('https://www.example.com'));
    $manifest = new SiteManifest($mapper, Origin::fromUrl('https://www.example.com'), new NoLanguages);
    (new ManifestRoutes($manifest))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();

    return rest_do_request(new WP_REST_Request('GET', '/headless/v1/site'));
}

it('serves the manifest of the site to a visitor: menus as trees with front-end paths, locations, content types', function (): void {
    register_nav_menu('headless_primary', 'Primary (test)');
    $page = wp_insert_post(['post_title' => 'About Us', 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about-us']);
    $menu = wp_create_nav_menu('Headless Main');
    $parent = wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'About', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1]);
    wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Docs', 'menu-item-url' => home_url('/docs/'), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent, 'menu-item-position' => 2]);
    wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Elsewhere', 'menu-item-url' => 'https://other.example.org/x', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 3]);
    set_theme_mod('nav_menu_locations', ['headless_primary' => $menu]);
    wp_set_current_user(0);

    $response = manifestRequest();
    $data = $response->get_data();
    $main = array_values(array_filter($data['menus'], static fn (array $m): bool => $m['slug'] === 'headless-main'))[0];
    $location = array_values(array_filter($data['locations'], static fn (array $l): bool => $l['location'] === 'headless_primary'))[0];

    set_theme_mod('nav_menu_locations', []);
    wp_delete_nav_menu($menu);
    wp_delete_post($page, true);
    unregister_nav_menu('headless_primary');

    expect($response->get_status())->toBe(200)
        ->and($data['version'])->toBe(1)
        ->and($data['site']['url'])->toBe('https://www.example.com')
        ->and($data['site']['wordpress_url'])->toBe(home_url())
        ->and($data['site']['multisite'])->toBeFalse()
        ->and($main['items'])->toHaveCount(2)
        ->and($main['items'][0]['title'])->toBe('About')
        ->and($main['items'][0]['url'])->toStartWith('https://www.example.com/')
        ->and($main['items'][0]['path'])->toStartWith('/')
        ->and($main['items'][0]['object_id'])->toBe($page)
        ->and($main['items'][0]['children'])->toHaveCount(1)
        ->and($main['items'][0]['children'][0]['url'])->toBe('https://www.example.com/docs/')
        ->and($main['items'][0]['children'][0]['path'])->toBe('/docs/')
        ->and($main['items'][1]['url'])->toBe('https://other.example.org/x')
        ->and($main['items'][1]['path'])->toBeNull()
        ->and($location)->toMatchArray(['menu' => 'headless-main', 'description' => 'Primary (test)'])
        ->and(array_column($data['post_types'], 'name'))->toContain('post', 'page')
        ->and(array_column($data['taxonomies'], 'name'))->toContain('category', 'post_tag')
        ->and($data['post_types'][array_search('post', array_column($data['post_types'], 'name'), true)])->toMatchArray(['rest_base' => 'posts', 'rest_namespace' => 'wp/v2']);
});

it('answers the manifest the same twice, so that its ETag holds', function (): void {
    $first = manifestRequest()->get_data();
    $second = manifestRequest()->get_data();

    expect(json_encode($second))->toBe(json_encode($first));
});
