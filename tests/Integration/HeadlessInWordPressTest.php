<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\Cors;
use Pollora\Headless\Infrastructure\WordPress\RestSecurity;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
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
