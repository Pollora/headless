<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Schema\OpenApiBuilder;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The frozen contract (docs/HTTP-CONTRACT.md) against a real WordPress: every route of the package is described in the document,
 * and the answers keep the keys the document promises. A change that breaks one of these breaks a client.
 */
function contractServer(): void
{
    Bootstrap::boot(Settings::fromArray(['frontend_url' => 'https://www.example.com', 'preview' => ['url' => '/api/preview']], home_url()), new WordPressHooks, new NoLanguages);
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
}

/**
 * @return array<string, mixed>
 */
function contractGet(string $route, array $params = []): array
{
    $request = new WP_REST_Request('GET', $route);
    $request->set_query_params($params);

    return rest_do_request($request)->get_data();
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

    delete_option('permalink_structure');
});

it('describes every route of the package in the contract document', function (): void {
    contractServer();
    $document = (string) file_get_contents(dirname(__DIR__, 2).'/docs/HTTP-CONTRACT.md');
    $built = (new OpenApiBuilder('t', '1', 'https://x.test', ['headless/v1']))->build(rest_get_server()->get_routes());

    $missing = [];

    foreach (array_keys($built['paths']) as $path) {
        $relative = substr($path, strlen('/headless/v1'));

        if (! str_contains($document, $relative)) {
            $missing[] = $path;
        }
    }

    expect($built['paths'])->not->toBeEmpty()->and($missing)->toBe([]);
});

it('keeps the keys of the manifest', function (): void {
    contractServer();

    $site = contractGet('/headless/v1/site');

    expect(array_keys($site))->toBe(['version', 'site', 'languages', 'front_page', 'menus', 'locations', 'post_types', 'taxonomies', 'permalink_structure'])
        ->and($site['version'])->toBe(1)
        ->and(array_keys($site['site']))->toBe(['id', 'name', 'description', 'url', 'wordpress_url', 'locale', 'language', 'timezone', 'date_format', 'time_format', 'multisite'])
        ->and(array_keys($site['front_page']))->toBe(['show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page'])
        ->and(array_keys($site['post_types'][0]))->toBe(['name', 'label', 'rest_base', 'rest_namespace', 'hierarchical', 'has_archive', 'rewrite_slug'])
        ->and(array_keys($site['taxonomies'][0]))->toBe(['name', 'label', 'rest_base', 'rest_namespace', 'hierarchical', 'post_types', 'rewrite_slug']);
});

it('keeps the keys of redirects, search and forms', function (): void {
    contractServer();
    $id = wp_insert_post(['post_title' => 'Contract finch', 'post_status' => 'publish']);

    $search = contractGet('/headless/v1/search', ['q' => 'finch']);
    $redirects = contractGet('/headless/v1/redirects');
    $resolve = contractGet('/headless/v1/redirects/resolve', ['path' => '/nowhere/']);
    $forms = contractGet('/headless/v1/forms');
    wp_delete_post($id, true);

    expect(array_keys($search))->toBe(['query', 'page', 'per_page', 'total', 'pages', 'results'])
        ->and(array_keys($search['results'][0]))->toBe(['id', 'type', 'title', 'excerpt', 'url', 'language', 'date', 'image'])
        ->and(array_keys($redirects))->toBe(['count', 'redirects', 'truncated'])
        ->and($resolve)->toBe(['redirect' => null])
        ->and(array_keys($forms))->toBe(['forms']);
});

it('keeps the keys of a file of the library', function (): void {
    contractServer();
    $file = wp_upload_bits('contract.txt', null, 'hello');
    $id = wp_insert_attachment(['post_mime_type' => 'text/plain', 'post_title' => 'Contract file', 'post_status' => 'inherit'], $file['file']);

    $media = contractGet("/headless/v1/media/$id");
    wp_delete_attachment($id, true);

    expect(array_keys($media))->toBe(['id', 'kind', 'mime', 'url', 'title', 'alt', 'caption', 'description', 'width', 'height', 'filesize', 'duration', 'focal_point', 'sizes', 'srcset', 'modified_gmt'])
        ->and($media['kind'])->toBe('file');
});
