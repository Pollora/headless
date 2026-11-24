<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * Redirects against a real WordPress: the real option, the real old slugs WordPress keeps, the real REST routes.
 */
function redirectsServer(): void
{
    $mapper = new UrlMapper(Origin::fromUrl(home_url()), Origin::fromUrl('https://www.example.com'));
    (new RedirectRoutes(new RedirectCatalog([new OptionRedirectStore, new OldSlugRedirects($mapper)])))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
}

beforeEach(function (): void {
    delete_option(OptionRedirectStore::OPTION);
    OldSlugRedirects::forget();
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
});

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

    delete_option(OptionRedirectStore::OPTION);
    OldSlugRedirects::forget();
    update_option('permalink_structure', '');
    $GLOBALS['wp_rewrite']->set_permalink_structure('');
});

it('lists the rules an editor manages, to a visitor', function (): void {
    $store = new OptionRedirectStore;
    $store->save(new Redirect('/old-page/', '/new-page/'));
    $store->save(new Redirect('/blog/(.*)', '/news/$1', 308, true));
    redirectsServer();
    wp_set_current_user(0);

    $response = rest_do_request(new WP_REST_Request('GET', '/headless/v1/redirects'));
    $data = $response->get_data();

    expect($response->get_status())->toBe(200)
        ->and($data['count'])->toBe(2)
        ->and($data['truncated'])->toBeFalse()
        ->and(array_column($data['redirects'], 'from'))->toBe(['/old-page/', '/blog/(.*)'])
        ->and($data['redirects'][1])->toMatchArray(['to' => '/news/$1', 'status' => 308, 'regex' => true, 'source' => 'manual']);
});

it('resolves a path, and answers 200 with null for one that goes nowhere', function (): void {
    (new OptionRedirectStore)->save(new Redirect('/blog/(.*)', '/news/$1', 308, true));
    redirectsServer();

    $ask = static function (string $path): WP_REST_Response {
        $request = new WP_REST_Request('GET', '/headless/v1/redirects/resolve');
        $request->set_param('path', $path);

        return rest_do_request($request);
    };

    expect($ask('/blog/hello')->get_data()['redirect'])->toBe(['from' => '/blog/(.*)', 'to' => '/news/hello', 'status' => 308, 'source' => 'manual'])
        ->and($ask('/elsewhere')->get_status())->toBe(200)
        ->and($ask('/elsewhere')->get_data())->toBe(['redirect' => null])
        ->and($ask('no-leading-slash')->get_status())->toBe(400)
        ->and($ask('/has space')->get_status())->toBe(400);
});

it('lists the old addresses WordPress remembers for a renamed post, each to the current one', function (): void {
    $id = wp_insert_post(['post_title' => 'First Title', 'post_status' => 'publish']);
    wp_update_post(['ID' => $id, 'post_name' => 'second-title']);
    wp_update_post(['ID' => $id, 'post_name' => 'third-title']);
    redirectsServer();

    $data = rest_do_request(new WP_REST_Request('GET', '/headless/v1/redirects'))->get_data();
    wp_delete_post($id, true);
    $old = array_values(array_filter($data['redirects'], static fn (array $rule): bool => $rule['source'] === 'old-slug'));

    expect(array_column($old, 'from'))->toContain('/first-title/', '/second-title/')
        ->and(array_unique(array_column($old, 'to')))->toBe(['/third-title/'])
        ->and($old[0]['status'])->toBe(301);
});

it('does not list the old address of a post that is not published', function (): void {
    $id = wp_insert_post(['post_title' => 'Draft Title', 'post_status' => 'publish']);
    wp_update_post(['ID' => $id, 'post_name' => 'renamed-draft']);
    wp_update_post(['ID' => $id, 'post_status' => 'draft']);
    OldSlugRedirects::forget();
    redirectsServer();

    $data = rest_do_request(new WP_REST_Request('GET', '/headless/v1/redirects'))->get_data();
    wp_delete_post($id, true);

    expect(array_column($data['redirects'], 'source'))->not->toContain('old-slug');
});

it('puts what an editor wrote before what WordPress remembers', function (): void {
    $id = wp_insert_post(['post_title' => 'Before', 'post_status' => 'publish']);
    wp_update_post(['ID' => $id, 'post_name' => 'after']);
    (new OptionRedirectStore)->save(new Redirect('/before/', '/somewhere-else/'));
    redirectsServer();

    $request = new WP_REST_Request('GET', '/headless/v1/redirects/resolve');
    $request->set_param('path', '/before/');
    $data = rest_do_request($request)->get_data();
    wp_delete_post($id, true);

    expect($data['redirect']['to'])->toBe('/somewhere-else/')->and($data['redirect']['source'])->toBe('manual');
});

it('forgets the list of old slugs when a post is saved', function (): void {
    $id = wp_insert_post(['post_title' => 'Cached', 'post_status' => 'publish']);
    redirectsServer();
    rest_do_request(new WP_REST_Request('GET', '/headless/v1/redirects'));
    $cached = get_transient(OldSlugRedirects::TRANSIENT);

    wp_update_post(['ID' => $id, 'post_title' => 'Cached again']);
    $afterSave = get_transient(OldSlugRedirects::TRANSIENT);
    wp_delete_post($id, true);

    expect($cached)->toBeArray()->and($afterSave)->toBeFalse();
});
