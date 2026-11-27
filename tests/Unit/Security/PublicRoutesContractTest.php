<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Media\MediaCatalog;
use Pollora\Headless\Domain\Seo\SeoProvider;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\FormRoutes;
use Pollora\Headless\Infrastructure\WordPress\ManifestRoutes;
use Pollora\Headless\Infrastructure\WordPress\MediaRoutes;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\PreviewRoutes;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\SeoField;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;

/*
 * What the package opens on the REST API: who may call each route, with which arguments, and on which
 * content types its fields appear.
 */

/** @return ArrayObject<string, array<string, mixed>> every route registered, by address */
function exposureRoutes(object $module): ArrayObject
{
    $routes = new ArrayObject;
    Functions\when('register_rest_route')->alias(static function (string $namespace, string $route, array $args) use ($routes): bool {
        $routes[$namespace.$route] = $args;

        return true;
    });
    $module->routes();

    return $routes;
}

function exposureAnything(string $interface): object
{
    return Mockery::mock($interface);
}

function exposurePreview(): PreviewRoutes
{
    return new PreviewRoutes(new PreviewService(new FakePreviewStore, new FakePostAccess, 'key', 'https://www.example.com'), new PreviewPostResponse);
}

it('opens the preview exchange to a POST carrying a token', function (): void {
    $module = exposurePreview();
    $route = exposureRoutes($module)['headless/v1/preview/exchange'];

    expect($route)->toBe([
        'methods' => 'POST',
        'callback' => [$module, 'exchange'],
        'permission_callback' => '__return_true',
        'args' => ['token' => ['type' => 'string', 'required' => true]],
    ]);
});

it('keeps a previewed post behind the permission check, with a strict revision argument', function (): void {
    $module = exposurePreview();
    $route = exposureRoutes($module)['headless/v1/preview/posts/(?P<id>\d+)'];

    expect($route['methods'])->toBe('GET')
        ->and($route['callback'])->toBe([$module, 'read'])
        ->and($route['permission_callback'])->toBe([$module, 'authorize'])
        ->and($route['args']['id'])->toBe(['type' => 'integer', 'required' => true])
        ->and($route['args']['rev'])->toBe(['type' => 'string', 'default' => 'autosave', 'pattern' => '\A(autosave|live|\d+)\z']);
});

it('opens the redirects to GET only, and takes one bounded path to resolve', function (): void {
    $module = new RedirectRoutes(new RedirectCatalog([]));
    $routes = exposureRoutes($module);

    expect($routes['headless/v1/redirects'])->toBe(['methods' => 'GET', 'callback' => [$module, 'all'], 'permission_callback' => '__return_true'])
        ->and($routes['headless/v1/redirects/resolve'])->toBe([
            'methods' => 'GET',
            'callback' => [$module, 'resolve'],
            'permission_callback' => '__return_true',
            'args' => ['path' => ['type' => 'string', 'required' => true, 'pattern' => '\A/\S{0,511}\z']],
        ]);
});

it('opens the forms and the manifest to GET only', function (): void {
    $forms = new FormRoutes([]);
    $manifest = new ManifestRoutes((new ReflectionClass(SiteManifest::class))->newInstanceWithoutConstructor());
    $routes = exposureRoutes($forms);

    expect($routes['headless/v1/forms'])->toBe(['methods' => 'GET', 'callback' => [$forms, 'index'], 'permission_callback' => '__return_true'])
        ->and($routes['headless/v1/forms/(?P<provider>[a-z0-9_-]+)/(?P<id>[A-Za-z0-9_-]+)'])->toBe(['methods' => 'GET', 'callback' => [$forms, 'show'], 'permission_callback' => '__return_true'])
        ->and(exposureRoutes($manifest)['headless/v1/site'])->toBe(['methods' => 'GET', 'callback' => [$manifest, 'show'], 'permission_callback' => '__return_true']);
});

it('opens a media file to GET by its number', function (): void {
    Functions\when('get_post_types')->justReturn([]);
    $module = new MediaRoutes(exposureAnything(MediaCatalog::class));

    expect(exposureRoutes($module)['headless/v1/media/(?P<id>\d+)'])->toBe([
        'methods' => 'GET',
        'callback' => [$module, 'show'],
        'permission_callback' => '__return_true',
        'args' => ['id' => ['type' => 'integer', 'required' => true]],
    ]);
});

it('opens the search to GET, with its arguments', function (): void {
    $module = new SearchRoutes(exposureAnything(FrontUrls::class), exposureAnything(Languages::class), 25);
    $route = exposureRoutes($module)['headless/v1/search'];

    expect($route['methods'])->toBe('GET')
        ->and($route['callback'])->toBe([$module, 'search'])
        ->and($route['permission_callback'])->toBe('__return_true')
        ->and($route['args'])->toBe([
            'q' => ['type' => 'string', 'required' => true, 'description' => 'The words to search for (two characters at least).'],
            'type' => ['type' => 'string', 'description' => 'Comma-separated content types to look in; every public type by default.'],
            'lang' => ['type' => 'string', 'description' => 'A language code, when the site has languages.'],
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'description' => 'Capped at 25.'],
        ]);
});

it('adds the featured image, the blocks and the SEO to public types the REST API serves, and to no other', function (string $module, array $expected): void {
    $instance = match ($module) {
        'media' => new MediaRoutes(exposureAnything(MediaCatalog::class)),
        'blocks' => new BlocksField,
        'seo' => new SeoField(exposureAnything(SeoProvider::class), exposureAnything(FrontUrls::class)),
    };
    $fields = new ArrayObject;
    $all = [
        'post' => ['public' => true, 'show_in_rest' => true],
        'secret' => ['public' => false, 'show_in_rest' => true],
        'classic' => ['public' => true, 'show_in_rest' => false],
    ];
    $matching = static fn (array $criteria): array => array_keys(array_filter($all, static fn (array $type): bool => array_intersect_assoc($criteria, $type) === $criteria));
    Functions\when('get_post_types')->alias($matching);
    Functions\when('get_taxonomies')->alias($matching);
    Functions\when('post_type_supports')->justReturn(true);
    Functions\when('register_rest_route')->justReturn(true);
    Functions\when('register_rest_field')->alias(static function (string $type, string $name, array $args) use ($fields): void {
        $fields[] = [$type, $name, $args['get_callback'][1], $args['schema']];
    });

    $module === 'media' ? $instance->routes() : $instance->fields();

    expect($fields->getArrayCopy())->toBe($expected);
})->with([
    'media' => ['media', [['post', 'headless_featured_media', 'featured', ['description' => 'The featured image in full (ask for it in _fields).', 'type' => ['object', 'null'], 'context' => ['view', 'edit']]]]],
    'blocks' => ['blocks', [['post', 'headless_blocks', 'blocks', ['description' => 'The blocks of the content as stable JSON (ask for it in _fields).', 'type' => ['array', 'null'], 'context' => ['view', 'edit']]]]],
    'seo' => ['seo', [
        ['post', 'headless_seo', 'forPost', ['description' => 'Search and social metadata in one shape (ask for it in _fields).', 'type' => ['object', 'null'], 'context' => ['view', 'edit']]],
        ['post', 'headless_seo', 'forTerm', ['description' => 'Search and social metadata in one shape (ask for it in _fields).', 'type' => ['object', 'null'], 'context' => ['view', 'edit']]],
    ]],
]);
