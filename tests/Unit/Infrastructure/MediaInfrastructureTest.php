<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Media\MediaCatalog;
use Pollora\Headless\Domain\Media\MediaUrls;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\MediaCdn;
use Pollora\Headless\Infrastructure\WordPress\MediaRoutes;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\OptInField;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function fieldsRequest(mixed $fields): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_param')->with('_fields')->andReturn($fields);

    return $request;
}

it('knows whether a request asked for a field by name', function (mixed $fields, bool $expected): void {
    expect(OptInField::requested(fieldsRequest($fields), 'headless_blocks'))->toBe($expected);
})->with([
    ['id,headless_blocks', true],
    ['id, headless_blocks ,title', true],
    [['id', 'headless_blocks'], true],
    ['id,title', false],
    ['headless_blocks_other', false],
    ['', false],
    [null, false],
]);

it('registers the media route, and a featured-media field on the types that have a thumbnail', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    $routes = new MediaRoutes(new NormalizedMedia);
    $routes->register(new WordPressHooks);

    Functions\when('get_post_types')->justReturn(['post', 'page', 'event']);
    Functions\when('post_type_supports')->alias(static fn (string $type, string $feature): bool => $type !== 'page' && $feature === 'thumbnail');
    Functions\expect('register_rest_route')->once()->with('headless/v1', '/media/(?P<id>\d+)', Mockery::type('array'));
    Functions\expect('register_rest_field')->twice()->with(Mockery::anyOf('post', 'event'), 'headless_featured_media', Mockery::type('array'));

    $routes->routes();
});

it('computes the featured image only when asked for it', function (): void {
    $media = Mockery::mock(MediaCatalog::class);
    $media->shouldReceive('for')->once()->with(55)->andReturn(['id' => 55]);
    $routes = new MediaRoutes($media);
    Functions\when('get_post_thumbnail_id')->alias(static fn (int $id): int => $id === 12 ? 55 : 0);

    expect($routes->featured(['id' => 12], 'headless_featured_media', fieldsRequest('id,headless_featured_media')))->toBe(['id' => 55])
        ->and($routes->featured(['id' => 12], 'headless_featured_media', fieldsRequest('id,title')))->toBeNull()
        ->and($routes->featured(['id' => 13], 'headless_featured_media', fieldsRequest('headless_featured_media')))->toBeNull();
});

it('answers 404 for a file that is not there, and the file otherwise', function (): void {
    $media = Mockery::mock(MediaCatalog::class);
    $media->shouldReceive('for')->with(1)->andReturn(null);
    $media->shouldReceive('for')->with(2)->andReturn(['id' => 2]);
    Functions\when('rest_ensure_response')->alias(static function (array $data): WP_REST_Response {
        $response = Mockery::mock('WP_REST_Response');
        $response->shouldReceive('get_data')->andReturn($data);

        return $response;
    });
    $routes = new MediaRoutes($media);
    $ask = static function (int $id): WP_REST_Request {
        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->with('id')->andReturn($id);

        return $request;
    };

    $missing = $routes->show($ask(1));

    expect($missing)->toBeInstanceOf(WP_Error::class)
        ->and($missing->get_error_code())->toBe('media_not_found')
        ->and($missing->get_error_data())->toBe(['status' => 404])
        ->and($routes->show($ask(2))->get_data())->toBe(['id' => 2]);
});

it('hooks the address of a file, its srcset and the content', function (): void {
    Filters\expectAdded('wp_get_attachment_url')->once();
    Filters\expectAdded('wp_calculate_image_srcset')->once();
    Filters\expectAdded('the_content')->once()->with(Mockery::type('array'), 20);

    (new MediaCdn(new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com')))->register(new WordPressHooks);
});

it('moves files to the CDN outside the administration, and leaves the administration alone', function (): void {
    $cdn = new MediaCdn(new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com'));
    $sources = [300 => ['url' => 'https://cms.example.com/wp-content/uploads/a-300.jpg', 'descriptor' => 'w', 'value' => 300], 600 => 'not a source'];

    Functions\when('is_admin')->justReturn(false);

    expect($cdn->url('https://cms.example.com/wp-content/uploads/a.jpg'))->toBe('https://cdn.example.com/a.jpg')
        ->and($cdn->url(false))->toBeFalse()
        ->and($cdn->srcset($sources)[300]['url'])->toBe('https://cdn.example.com/a-300.jpg')
        ->and($cdn->srcset($sources)[600])->toBe('not a source')
        ->and($cdn->srcset(false))->toBeFalse()
        ->and($cdn->html('<img src="https://cms.example.com/wp-content/uploads/a.jpg">'))->toBe('<img src="https://cdn.example.com/a.jpg">')
        ->and($cdn->html(['not html']))->toBe(['not html']);

    Functions\when('is_admin')->justReturn(true);

    expect($cdn->url('https://cms.example.com/wp-content/uploads/a.jpg'))->toBe('https://cms.example.com/wp-content/uploads/a.jpg')
        ->and($cdn->srcset($sources))->toBe($sources)
        ->and($cdn->html('<img src="https://cms.example.com/wp-content/uploads/a.jpg">'))->toBe('<img src="https://cms.example.com/wp-content/uploads/a.jpg">');
});

it('reads the media settings', function (): void {
    $on = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'media' => ['cdn_url' => 'https://cdn.example.com/']], 'https://cms.example.com');
    $off = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'media' => ['enabled' => false]], 'https://cms.example.com');
    $bare = Settings::fromArray([], 'https://cms.example.com');

    expect($on->mediaRoutes)->toBeTrue()->and($on->mediaCdn)->toBe('https://cdn.example.com')
        ->and($off->mediaRoutes)->toBeFalse()->and($off->mediaCdn)->toBeNull()
        ->and($bare->mediaRoutes)->toBeFalse()->and($bare->mediaCdn)->toBeNull();
});

it('refuses a CDN address that is not one', function (): void {
    Settings::fromArray(['media' => ['cdn_url' => 'cdn.example.com']], 'https://cms.example.com');
})->throws(InvalidArgumentException::class);
