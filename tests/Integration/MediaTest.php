<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Media\MediaUrls;
use Pollora\Headless\Infrastructure\WordPress\MediaCdn;
use Pollora\Headless\Infrastructure\WordPress\MediaRoutes;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * Media against a real WordPress: a real attachment, its real metadata, the real REST routes and filters. The file
 * itself is not needed: the metadata says what WordPress would have generated.
 */
function mediaServer(): void
{
    (new MediaRoutes(new NormalizedMedia))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
}

function imageAttachment(int $parent = 0, string $name = 'headless-test'): int
{
    $id = wp_insert_attachment([
        'post_title' => 'A test image',
        'post_excerpt' => 'Caption of it',
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
        'post_parent' => $parent,
    ], "2026/11/$name.png");

    update_post_meta($id, '_wp_attachment_image_alt', 'An alternative text');
    wp_update_attachment_metadata($id, [
        'width' => 1200,
        'height' => 800,
        'file' => "2026/11/$name.png",
        'filesize' => 54321,
        'sizes' => [
            'thumbnail' => ['file' => "$name-150x150.png", 'width' => 150, 'height' => 150, 'mime-type' => 'image/png'],
            'medium' => ['file' => "$name-300x200.png", 'width' => 300, 'height' => 200, 'mime-type' => 'image/png'],
        ],
    ]);

    return $id;
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

    remove_all_filters('pollora/media/focal_point');
    wp_set_current_user(0);
});

it('describes an image in one shape: sizes, srcset, alt, caption', function (): void {
    mediaServer();
    $id = imageAttachment();

    $response = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"));
    $data = $response->get_data();
    wp_delete_attachment($id, true);

    expect($response->get_status())->toBe(200)
        ->and($data)->toMatchArray(['id' => $id, 'kind' => 'image', 'mime' => 'image/png', 'title' => 'A test image', 'alt' => 'An alternative text', 'caption' => 'Caption of it', 'width' => 1200, 'height' => 800, 'filesize' => 54321, 'duration' => null, 'focal_point' => null])
        ->and($data['url'])->toEndWith('/2026/11/headless-test.png')
        ->and(array_keys($data['sizes']))->toBe(['medium', 'thumbnail', 'full'])
        ->and($data['sizes']['medium'])->toMatchArray(['width' => 300, 'height' => 200])
        ->and($data['sizes']['medium']['url'])->toEndWith('/2026/11/headless-test-300x200.png')
        ->and($data['sizes']['full']['url'])->toBe($data['url'])
        ->and($data['srcset'])->toContain('headless-test-300x200.png 300w')
        ->and($data['modified_gmt'])->toMatch('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\z/');
});

it('gives the focal point the editor set with pollora/media, and lets its filter have the last word', function (): void {
    mediaServer();
    $id = imageAttachment();
    update_post_meta($id, '_pollora_focal_point', ['x' => 0.25, 'y' => 0.75]);

    $stored = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"))->get_data()['focal_point'];

    add_filter('pollora/media/focal_point', static fn (mixed $point, int $attachment): array => ['x' => 0.5, 'y' => 0.5], 10, 2);
    $filtered = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"))->get_data()['focal_point'];
    remove_all_filters('pollora/media/focal_point');

    update_post_meta($id, '_pollora_focal_point', ['x' => 9, 'y' => 'nope']);
    $broken = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"))->get_data()['focal_point'];
    wp_delete_attachment($id, true);

    expect($stored)->toBe(['x' => 0.25, 'y' => 0.75])
        ->and($filtered)->toBe(['x' => 0.5, 'y' => 0.5])
        ->and($broken)->toBeNull();
});

it('describes a document without image fields', function (): void {
    mediaServer();
    $id = wp_insert_attachment(['post_title' => 'A report', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], '2026/11/report.pdf');

    $data = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"))->get_data();
    wp_delete_attachment($id, true);

    expect($data['kind'])->toBe('file')
        ->and($data['width'])->toBeNull()
        ->and($data['sizes'])->toBe([])
        ->and($data['srcset'])->toBeNull()
        ->and($data['focal_point'])->toBeNull();
});

it('answers 404 for a file that is not there, a post that is not a file, and a file of a draft to a visitor', function (): void {
    mediaServer();
    $draft = wp_insert_post(['post_title' => 'Draft', 'post_status' => 'draft']);
    $attached = imageAttachment($draft, 'attached-to-draft');
    $post = wp_insert_post(['post_title' => 'Not a file', 'post_status' => 'publish']);

    wp_set_current_user(0);
    $visitor = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$attached"));
    $missing = rest_do_request(new WP_REST_Request('GET', '/headless/v1/media/999999'));
    $notFile = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$post"));
    wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);
    $editor = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$attached"));
    wp_set_current_user(0);
    wp_delete_attachment($attached, true);
    wp_delete_post($draft, true);
    wp_delete_post($post, true);

    expect($visitor->get_status())->toBe(404)
        ->and($visitor->get_data()['code'])->toBe('media_not_found')
        ->and($missing->get_status())->toBe(404)
        ->and($notFile->get_status())->toBe(404)
        ->and($editor->get_status())->toBe(200);
});

it('gives the featured image of a post in full, only when _fields asks for it', function (): void {
    mediaServer();
    $image = imageAttachment();
    $post = wp_insert_post(['post_title' => 'With image', 'post_status' => 'publish']);
    set_post_thumbnail($post, $image);

    $ask = static function (string $fields) use ($post): array {
        $request = new WP_REST_Request('GET', "/wp/v2/posts/$post");
        $request->set_param('_fields', $fields);

        return rest_do_request($request)->get_data();
    };

    $with = $ask('id,headless_featured_media');
    $without = $ask('id,featured_media');
    $all = rest_do_request(new WP_REST_Request('GET', "/wp/v2/posts/$post"))->get_data();
    wp_delete_post($post, true);
    wp_delete_attachment($image, true);

    expect($with['headless_featured_media'])->toMatchArray(['id' => $image, 'kind' => 'image', 'width' => 1200])
        ->and($without)->not->toHaveKey('headless_featured_media')
        ->and($all['headless_featured_media'])->toBeNull();
});

it('serves the files from the CDN, in the address, the srcset and the content, and not in the administration', function (): void {
    $uploads = wp_upload_dir(null, false)['baseurl'];
    (new MediaCdn(new MediaUrls($uploads, 'https://cdn.example.com')))->register(new WordPressHooks);
    mediaServer();
    $id = imageAttachment();

    $url = wp_get_attachment_url($id);
    $srcset = wp_get_attachment_image_srcset($id, 'full');
    $content = apply_filters('the_content', '<p><img src="'.$uploads.'/2026/11/headless-test.png"><a href="'.home_url('/page/').'">p</a></p>');
    $api = rest_do_request(new WP_REST_Request('GET', "/headless/v1/media/$id"))->get_data();
    // is_admin() reads the screen: the one of the administration
    require_once ABSPATH.'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH.'wp-admin/includes/screen.php';
    set_current_screen('dashboard');
    $inAdmin = wp_get_attachment_url($id);
    unset($GLOBALS['current_screen']);
    $GLOBALS['current_screen'] = WP_Screen::get('front');
    wp_delete_attachment($id, true);

    expect($url)->toBe('https://cdn.example.com/2026/11/headless-test.png')
        ->and($srcset)->toContain('https://cdn.example.com/2026/11/headless-test-300x200.png 300w')
        ->and($srcset)->not->toContain($uploads)
        ->and($content)->toContain('src="https://cdn.example.com/2026/11/headless-test.png"')
        ->and($content)->toContain('href="'.home_url('/page/').'"')
        ->and($api['url'])->toBe('https://cdn.example.com/2026/11/headless-test.png')
        ->and($api['sizes']['medium']['url'])->toBe('https://cdn.example.com/2026/11/headless-test-300x200.png')
        ->and($inAdmin)->toBe($uploads.'/2026/11/headless-test.png');
});
