<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The search endpoint against a real WordPress: what it finds, what it leaves out, and how each result looks.
 */
function searchServer(int $max = 50): void
{
    $mapper = new UrlMapper(Origin::fromUrl(home_url()), Origin::fromUrl('https://www.example.com'));
    (new SearchRoutes($mapper, new NoLanguages, $max))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
}

function searchFor(string $query, array $params = []): WP_REST_Response
{
    $request = new WP_REST_Request('GET', '/headless/v1/search');
    $request->set_query_params(['q' => $query] + $params);

    return rest_do_request($request);
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
});

it('finds published content and answers with a stable shape', function (): void {
    searchServer();
    $post = wp_insert_post(['post_title' => 'Zebrafinch migration', 'post_content' => '<p>The <b>zebrafinch</b> flies far.</p>', 'post_status' => 'publish']);
    $draft = wp_insert_post(['post_title' => 'Zebrafinch draft', 'post_status' => 'draft']);
    $locked = wp_insert_post(['post_title' => 'Zebrafinch secret', 'post_status' => 'publish', 'post_password' => 'x']);

    $response = searchFor('zebrafinch');
    $data = $response->get_data();
    foreach ([$post, $draft, $locked] as $id) {
        wp_delete_post($id, true);
    }

    expect($response->get_status())->toBe(200)
        ->and($data['total'])->toBe(1)
        ->and($data['results'])->toHaveCount(1)
        ->and(array_keys($data['results'][0]))->toBe(['id', 'type', 'title', 'excerpt', 'url', 'language', 'date', 'image'])
        ->and($data['results'][0]['title'])->toBe('Zebrafinch migration')
        ->and($data['results'][0]['excerpt'])->toBe('The zebrafinch flies far.')
        ->and($data['results'][0]['url'])->toStartWith('https://www.example.com/')
        ->and($data['results'][0]['language'])->toBeNull()
        ->and($response->get_headers()['X-WP-Total'])->toBe('1');
});

it('looks in the types asked for only', function (): void {
    searchServer();
    $page = wp_insert_post(['post_title' => 'Quokka page', 'post_type' => 'page', 'post_status' => 'publish']);
    $post = wp_insert_post(['post_title' => 'Quokka post', 'post_status' => 'publish']);

    $pages = searchFor('quokka', ['type' => 'page'])->get_data();
    $unknown = searchFor('quokka', ['type' => 'nothing'])->get_data();
    wp_delete_post($page, true);
    wp_delete_post($post, true);

    expect(array_column($pages['results'], 'type'))->toBe(['page'])
        ->and($unknown['total'])->toBe(0);
});

it('pages the results and caps their number', function (): void {
    searchServer(2);
    $ids = [];
    foreach (range(1, 3) as $n) {
        $ids[] = wp_insert_post(['post_title' => "Numbat $n", 'post_status' => 'publish']);
    }

    $first = searchFor('numbat', ['per_page' => 50])->get_data();
    $second = searchFor('numbat', ['per_page' => 50, 'page' => 2])->get_data();
    array_map(static fn (int $id) => wp_delete_post($id, true), $ids);

    expect($first['per_page'])->toBe(2)
        ->and($first['pages'])->toBe(2)
        ->and($first['results'])->toHaveCount(2)
        ->and($second['results'])->toHaveCount(1);
});

it('refuses a term that is too short', function (): void {
    searchServer();

    expect(searchFor('a')->get_status())->toBe(400)->and(searchFor('a')->get_data()['code'])->toBe('headless_search_term');
});
