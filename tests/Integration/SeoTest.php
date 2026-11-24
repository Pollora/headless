<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Seo\SeoProviders;
use Pollora\Headless\Infrastructure\WordPress\SeoField;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * SEO against a real Yoast SEO, installed by the CI job: the head JSON it adds to the REST API, read from inside,
 * mapped to the common shape, with every address moved to the front end.
 */
function seoServer(): void
{
    $mapper = new UrlMapper(Origin::fromUrl(home_url()), Origin::fromUrl('https://www.example.com'));
    (new SeoField(SeoProviders::detect(), $mapper))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
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

it('detects the SEO plugin the site runs', function (): void {
    expect(SeoProviders::detect()->name())->toBe('yoast');
});

it('gives the metadata of a post in the common shape, with its addresses on the front end', function (): void {
    seoServer();
    $id = wp_insert_post(['post_title' => 'A page about SEO', 'post_content' => 'Some content about it.', 'post_status' => 'publish']);

    $request = new WP_REST_Request('GET', "/wp/v2/posts/$id");
    $request->set_param('_fields', 'id,headless_seo');
    $data = rest_do_request($request)->get_data();
    $without = rest_do_request(new WP_REST_Request('GET', "/wp/v2/posts/$id"))->get_data();
    wp_delete_post($id, true);
    $seo = $data['headless_seo'];

    expect($seo['source'])->toBe('yoast')
        ->and(array_keys($seo))->toBe(['source', 'title', 'description', 'robots', 'canonical', 'open_graph', 'twitter', 'json_ld', 'breadcrumbs'])
        ->and($seo['title'])->toContain('A page about SEO')
        ->and($seo['robots']['index'])->toBeTrue()
        ->and($seo['canonical'])->toStartWith('https://www.example.com/')
        ->and($seo['open_graph']['url'])->toStartWith('https://www.example.com/')
        ->and($seo['open_graph']['title'])->not->toBeNull()
        ->and($seo['json_ld'])->not->toBeEmpty()
        ->and(json_encode($seo['json_ld']))->not->toContain(home_url())
        ->and($without['headless_seo'])->toBeNull();
});

it('gives the metadata of a term', function (): void {
    seoServer();
    $term = wp_insert_term('SEO Category', 'category');

    $request = new WP_REST_Request('GET', '/wp/v2/categories/'.$term['term_id']);
    $request->set_param('_fields', 'id,headless_seo');
    $seo = rest_do_request($request)->get_data()['headless_seo'];
    wp_delete_term($term['term_id'], 'category');

    expect($seo['source'])->toBe('yoast')
        ->and($seo['title'])->toContain('SEO Category')
        ->and($seo['canonical'])->toStartWith('https://www.example.com/');
});
