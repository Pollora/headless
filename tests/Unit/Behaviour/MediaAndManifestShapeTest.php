<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Manifest\MenuTree;
use Pollora\Headless\Domain\Media\FocalPoint;
use Pollora\Headless\Domain\Media\MediaKind;
use Pollora\Headless\Domain\Media\MediaUrls;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;

/*
 * The shape of a media file, of a menu and of the manifest, value by value.
 */

/** The file 9 of the library. */
function shapeFile(string $mime, array $metadata, array $over = []): void
{
    Functions\when('get_post')->justReturn(new WP_Post($over + ['ID' => 9, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Title', 'post_excerpt' => 'Caption', 'post_content' => 'Description', 'post_modified_gmt' => '2026-03-01 10:00:00']));
    Functions\when('get_post_mime_type')->justReturn($mime);
    Functions\when('wp_get_attachment_url')->justReturn('https://cms.example.com/u/2026/a.jpg');
    Functions\when('wp_get_attachment_metadata')->justReturn($metadata);
    Functions\when('get_post_meta')->alias(static fn (int $id, string $key, bool $single = false): mixed => $single
        ? ($key === '_wp_attachment_image_alt' ? 'Alt' : ['x' => '0.25', 'y' => 0.75])
        : ['not the single value']);
    Functions\when('wp_get_attachment_image_srcset')->justReturn('set 1x');
    Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
}

it('describes an image with its sizes, in the order of their names', function (): void {
    shapeFile('image/jpeg', ['width' => '1200', 'height' => 800, 'filesize' => '5000', 'length' => 3, 'sizes' => [
        'thumbnail' => ['file' => 't.jpg', 'width' => '150', 'height' => 100],
        'large' => ['file' => 'l.jpg'],
        'broken' => ['width' => 1],
        'junk' => 'x',
    ]]);

    expect((new NormalizedMedia)->for(9))->toBe([
        'id' => 9,
        'kind' => 'image',
        'mime' => 'image/jpeg',
        'url' => 'https://cms.example.com/u/2026/a.jpg',
        'title' => 'Title',
        'alt' => 'Alt',
        'caption' => 'Caption',
        'description' => 'Description',
        'width' => 1200,
        'height' => 800,
        'filesize' => 5000,
        'duration' => null,
        'focal_point' => ['x' => 0.25, 'y' => 0.75],
        'sizes' => [
            'large' => ['url' => 'https://cms.example.com/u/2026/l.jpg', 'width' => 0, 'height' => 0],
            'thumbnail' => ['url' => 'https://cms.example.com/u/2026/t.jpg', 'width' => 150, 'height' => 100],
            'full' => ['url' => 'https://cms.example.com/u/2026/a.jpg', 'width' => 1200, 'height' => 800],
        ],
        'srcset' => 'set 1x',
        'modified_gmt' => '2026-03-01T10:00:00',
    ]);
});

it('describes a video with its length, and none of what only an image has', function (): void {
    shapeFile('video/mp4', ['width' => 1920, 'height' => 1080, 'length' => '62']);

    $media = (new NormalizedMedia)->for(9);

    expect([$media['kind'], $media['width'], $media['height'], $media['filesize'], $media['duration'], $media['focal_point'], $media['sizes'], $media['srcset']])
        ->toBe(['video', null, null, null, 62, null, [], null]);
});

it('gives no full size to an image missing a measure', function (array $metadata): void {
    shapeFile('image/png', $metadata);

    expect((new NormalizedMedia)->for(9)['sizes'])->toBe([]);
})->with([[['width' => 10]], [['height' => 10]]]);

it('takes the focal point a filter gives, and keeps the stored one when the filter gives nonsense', function (mixed $filtered, array $expected): void {
    shapeFile('image/png', []);
    Functions\when('apply_filters')->justReturn($filtered);

    expect((new NormalizedMedia)->for(9)['focal_point'])->toBe($expected);
})->with([
    [['x' => 1, 'y' => 0], ['x' => 1.0, 'y' => 0.0]],
    ['nonsense', ['x' => 0.25, 'y' => 0.75]],
]);

it('reads a focal point from 0 to 1 on both axes, and nothing outside', function (mixed $x, mixed $y, bool $valid): void {
    $point = FocalPoint::tryFrom(['x' => $x, 'y' => $y]);

    expect($point?->toArray())->toBe($valid ? ['x' => (float) $x, 'y' => (float) $y] : null);
})->with([
    [0, 0, true], [1, 1, true], ['0.5', '1', true], [-0.1, 0, false], [0, -0.1, false], [1.1, 0, false], [0, 1.1, false], ['a', 0, false], [0, 'a', false], [null, 0, false],
]);

it('tells the kind of a file from its type, whatever its case', function (string $mime, MediaKind $kind): void {
    expect(MediaKind::fromMime($mime))->toBe($kind);
})->with([['IMAGE/PNG', MediaKind::Image], ['Video/mp4', MediaKind::Video], ['audio/ogg', MediaKind::Audio], ['image', MediaKind::File], ['', MediaKind::File]]);

it('moves uploads to a CDN written with a trailing slash, and nothing else', function (): void {
    $urls = new MediaUrls('https://cms.example.com/u/', 'https://cdn.example.com/');

    expect($urls->toCdn('http://cms.example.com/u/a.jpg'))->toBe('https://cdn.example.com/a.jpg')
        ->and($urls->toCdn('https://cms.example.com/u'))->toBe('https://cdn.example.com')
        ->and($urls->toCdn('https://cms.example.com/uploads/a.jpg'))->toBe('https://cms.example.com/uploads/a.jpg')
        ->and($urls->rewriteHtml('<img src="https://cms.example.com/u/a.jpg">'))->toBe('<img src="https://cdn.example.com/a.jpg">');
});

it('builds a tree that loses no item, whatever points at what', function (): void {
    $item = static fn (int $id, int $parent, int $order): array => ['id' => $id, 'parent' => $parent, 'order' => $order];
    $names = static function (array $tree) use (&$names): array {
        return array_map(static fn (array $node): array => [$node['id'], $names($node['children'])], $tree);
    };

    expect($names(MenuTree::build([$item(1, 1, 1), $item(2, 99, 2), $item(3, 1, 3)])))->toBe([[1, [[3, []]]], [2, []]])
        ->and($names(MenuTree::build([$item(5, 6, 1), $item(6, 5, 2), $item(7, 6, 3), $item(8, 0, 4)])))->toBe([[8, []], [5, [[6, [[7, []]]]]]])
        ->and($names(MenuTree::build([$item(1, 2, 1), $item(2, 1, 2), $item(3, 4, 3), $item(4, 3, 4)])))->toBe([[1, [[2, []]]], [3, [[4, []]]]]);
});

it('keeps the children of a parent that stays, when one of them leaves a circle', function (): void {
    $item = static fn (int $id, int $parent, int $order): array => ['id' => $id, 'parent' => $parent, 'order' => $order];

    $tree = MenuTree::build([$item(2, 3, 1), $item(3, 2, 2), $item(4, 3, 3)]);

    expect(array_column($tree, 'id'))->toBe([2])
        ->and(array_column($tree[0]['children'], 'id'))->toBe([3])
        ->and(array_column($tree[0]['children'][0]['children'], 'id'))->toBe([4]);
});

it('lists a menu item that has nothing but itself', function (): void {
    foreach (['get_current_blog_id' => 1, 'home_url' => 'https://cms.example.com', 'get_locale' => 'pt_BR', 'wp_timezone_string' => 'UTC', 'is_multisite' => false, 'get_nav_menu_locations' => [], 'get_post_types' => [], 'get_taxonomies' => []] as $function => $value) {
        Functions\when($function)->justReturn($value);
    }
    Functions\when('get_bloginfo')->returnArg();
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
    Functions\when('wp_get_nav_menus')->justReturn([(object) ['term_id' => 1, 'slug' => 'm', 'name' => 'M']]);
    Functions\when('wp_get_nav_menu_items')->justReturn(['junk', new stdClass]);
    Functions\when('get_registered_nav_menus')->justReturn([]);
    $urls = Mockery::mock(FrontUrls::class);
    $urls->shouldReceive('toFront')->andReturnArg(0);
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn(false);
    $languages->shouldReceive('current')->andReturn(null);

    $manifest = (new SiteManifest($urls, Origin::fromUrl('https://www.example.com'), $languages))->build();

    expect($manifest['menus'][0]['items'])->toBe([['id' => 0, 'title' => '', 'url' => '', 'path' => null, 'target' => '', 'classes' => [], 'type' => '', 'object' => '', 'object_id' => 0, 'children' => []]])
        ->and($manifest['site']['language'])->toBe('pt')
        ->and($manifest['languages'])->toBe([])
        ->and($manifest['permalink_structure'])->toBe('');
});

it('lists only the types and taxonomies that are public and served by the REST API', function (): void {
    foreach (['get_current_blog_id' => 1, 'home_url' => 'https://cms.example.com', 'get_locale' => 'en_US', 'wp_timezone_string' => 'UTC', 'is_multisite' => false, 'get_nav_menu_locations' => [], 'wp_get_nav_menus' => [], 'get_registered_nav_menus' => []] as $function => $value) {
        Functions\when($function)->justReturn($value);
    }
    Functions\when('get_bloginfo')->returnArg();
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);
    $all = [];

    foreach (['open' => [true, true], 'private' => [false, true], 'classic' => [true, false]] as $name => [$public, $rest]) {
        $all[$name] = (object) ['name' => $name, 'label' => $name, 'public' => $public, 'show_in_rest' => $rest, 'rest_base' => '', 'rest_namespace' => '', 'hierarchical' => 1, 'has_archive' => false, 'rewrite' => false, 'object_type' => []];
    }

    $matching = static fn (array $criteria, string $output): array => array_filter($all, static fn (object $type): bool => array_intersect_assoc($criteria, (array) $type) === $criteria);
    Functions\when('get_post_types')->alias($matching);
    Functions\when('get_taxonomies')->alias($matching);
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn(false);
    $languages->shouldReceive('current')->andReturn('en');

    $manifest = (new SiteManifest(Mockery::mock(FrontUrls::class), Origin::fromUrl('https://www.example.com'), $languages))->build();

    expect(array_column($manifest['post_types'], 'name'))->toBe(['open'])
        ->and(array_column($manifest['taxonomies'], 'name'))->toBe(['open'])
        ->and($manifest['post_types'][0]['hierarchical'])->toBeTrue()
        ->and($manifest['taxonomies'][0]['hierarchical'])->toBeTrue();
});

it('moves the address of each social image and breadcrumb, keeping the rest', function (): void {
    $seo = new Seo('x',
        openGraph: ['type' => null, 'title' => null, 'description' => null, 'url' => null, 'site_name' => null, 'locale' => null, 'images' => [['url' => 'a', 'width' => 1, 'height' => 2, 'alt' => 'A']]],
        jsonLd: [['n' => 3, 'url' => 'https://a.test/', 'text' => 'see https://a.test/']],
        breadcrumbs: [['label' => 'Home', 'url' => 'a']],
    );

    $moved = $seo->withUrls(static fn (string $url): string => 'moved '.$url);

    expect($moved->openGraph['images'])->toBe([['url' => 'moved a', 'width' => 1, 'height' => 2, 'alt' => 'A']])
        ->and($moved->breadcrumbs)->toBe([['label' => 'Home', 'url' => 'moved a']])
        ->and($moved->jsonLd)->toBe([['n' => 3, 'url' => 'moved https://a.test/', 'text' => 'see https://a.test/']]);
});
