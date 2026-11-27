<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Language\LanguageUrls;
use Pollora\Headless\Domain\Search\SearchQuery;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/*
 * What the public routes read of the site: the search and the manifest.
 */

afterEach(function (): void {
    WP_Query::$answer = [];
    WP_Query::$queries = [];
});

/** English at the root of the WordPress host, French under /fr/, each with a front end of its own. */
function twoLanguages(): Languages
{
    return new class implements Languages
    {
        public function plugin(): string
        {
            return 'polylang';
        }

        public function isActive(): bool
        {
            return true;
        }

        public function all(): array
        {
            return ['en', 'fr', 'de'];
        }

        public function current(): ?string
        {
            return 'fr';
        }

        public function ofPost(int $postId): ?string
        {
            return $postId === 2 ? 'fr' : 'en';
        }

        public function translations(int $postId): array
        {
            return [];
        }

        public function homeUrl(string $code): ?string
        {
            return ['en' => 'https://cms.example.com/', 'fr' => 'https://cms.example.com/fr/'][$code] ?? null;
        }
    };
}

function bilingualUrls(Languages $languages): LanguageUrls
{
    $backend = Origin::fromUrl('https://cms.example.com');
    $front = Origin::fromUrl('https://www.example.com');

    return new LanguageUrls(new UrlMapper($backend, $front), $backend, $front, ['fr' => Origin::fromUrl('https://www.example.fr')], $languages);
}

function searchSite(): void
{
    Functions\when('get_post_types')->alias(static fn (array $args): array => $args === ['public' => true, 'exclude_from_search' => false] ? ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'] : []);
    Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);
    Functions\when('wp_strip_all_tags')->alias('strip_tags');
    Functions\when('strip_shortcodes')->alias(static fn (string $text): string => str_replace('[gallery]', '', $text));
    Functions\when('wp_trim_words')->alias(static fn (string $text, int $words): string => $text.' ('.$words.')');
    Functions\when('get_the_title')->alias(static fn (WP_Post $post): string => $post->post_title);
    Functions\when('get_permalink')->alias(static fn (WP_Post $post): string => 'https://cms.example.com/p/'.$post->ID.'/');
    Functions\when('get_post_time')->alias(static fn (string $format, bool $gmt): string => $format.($gmt ? ' gmt' : ' local'));
    Functions\when('get_post_thumbnail_id')->alias(static fn (WP_Post $post): int => $post->ID === 2 ? 9 : 0);
    Functions\when('wp_get_attachment_url')->alias(static fn (int $id): string => 'https://cms.example.com/u/'.$id.'.jpg');
}

/**
 * @param  array<string, mixed>  $params
 */
function searchRequest(array $params): WP_REST_Request
{
    $request = new WP_REST_Request('GET', '/headless/v1/search');

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return $request;
}

it('registers a public search route that announces its ceiling', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    $routes = new SearchRoutes(bilingualUrls(twoLanguages()), new NoLanguages, 25);
    $routes->register(new WordPressHooks);

    Functions\expect('register_rest_route')->once()->with('headless/v1', '/search', Mockery::on(static fn (array $args): bool => $args['methods'] === 'GET'
        && $args['permission_callback'] === '__return_true'
        && $args['args']['q']['required'] === true
        && $args['args']['page']['minimum'] === 1
        && $args['args']['per_page']['default'] === SearchQuery::DEFAULT_PER_PAGE
        && $args['args']['per_page']['description'] === 'Capped at 25.'));

    $routes->routes();
});

it('searches only published, unprotected content of the public types, never the media', function (): void {
    searchSite();
    WP_Query::$answer = ['posts' => [], 'found_posts' => 0, 'max_num_pages' => 0];

    (new SearchRoutes(bilingualUrls(twoLanguages()), twoLanguages(), 20))->search(searchRequest(['q' => 'hello', 'type' => 'post,attachment,secret', 'lang' => 'fr', 'page' => 3, 'per_page' => 500]));

    expect(WP_Query::$queries)->toBe([[
        's' => 'hello',
        'post_type' => ['post'],
        'post_status' => 'publish',
        'has_password' => false,
        'posts_per_page' => 20,
        'paged' => 3,
        'ignore_sticky_posts' => true,
        'no_found_rows' => false,
        'lang' => 'fr',
    ]]);
});

it('looks in nothing when only types the site does not expose are asked for', function (): void {
    searchSite();

    (new SearchRoutes(bilingualUrls(twoLanguages()), new NoLanguages))->search(searchRequest(['q' => 'hello', 'type' => 'attachment', 'lang' => 'xx']));

    expect(WP_Query::$queries[0]['post_type'])->toBe(['__none__'])
        ->and(WP_Query::$queries[0])->not->toHaveKey('lang');
});

it('refuses a term too short to search for, without querying', function (): void {
    searchSite();

    $error = (new SearchRoutes(bilingualUrls(twoLanguages()), new NoLanguages))->search(searchRequest(['q' => ' a ']));

    expect([$error->get_error_code(), $error->get_error_message(), $error->get_error_data()])->toBe(['headless_search_term', 'Search for at least 2 characters.', ['status' => 400]])
        ->and(WP_Query::$queries)->toBe([]);
});

it('gives each result its front-end address, its language and a short text', function (): void {
    searchSite();
    $french = new WP_Post(['ID' => 2, 'post_type' => 'page', 'post_title' => 'Caf&eacute; &amp; th&eacute;', 'post_excerpt' => '<b>Un</b> r&eacute;sum&eacute;', 'post_content' => 'unused']);
    $english = new WP_Post(['ID' => 1, 'post_type' => 'post', 'post_title' => 'Tea', 'post_excerpt' => '', 'post_content' => '<p>Body[gallery]</p>']);
    WP_Query::$answer = ['posts' => [$french, 17, $english], 'found_posts' => 12, 'max_num_pages' => 6];

    $response = (new SearchRoutes(bilingualUrls(twoLanguages()), twoLanguages()))->search(searchRequest(['q' => '  the   tea ', 'per_page' => 2]));

    expect($response->get_data())->toBe([
        'query' => 'the tea',
        'page' => 1,
        'per_page' => 2,
        'total' => 12,
        'pages' => 6,
        'results' => [
            ['id' => 2, 'type' => 'page', 'title' => 'Café & thé', 'excerpt' => 'Un résumé (30)', 'url' => 'https://www.example.fr/p/2/', 'language' => 'fr', 'date' => 'c gmt', 'image' => 'https://cms.example.com/u/9.jpg'],
            ['id' => 1, 'type' => 'post', 'title' => 'Tea', 'excerpt' => 'Body (30)', 'url' => 'https://www.example.com/p/1/', 'language' => 'en', 'date' => 'c gmt', 'image' => null],
        ],
    ])->and($response->get_headers())->toBe(['X-WP-Total' => '12', 'X-WP-TotalPages' => '6']);
});

it('says which site a request is for', function (): void {
    Functions\when('get_current_blog_id')->justReturn(3);
    Functions\when('get_locale')->justReturn('de_DE');
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('home_url')->justReturn('https://cms.example.com/de');

    expect((array) SiteContext::current())->toBe(['siteId' => 3, 'locale' => 'de_DE', 'multisite' => true, 'homeUrl' => 'https://cms.example.com/de']);
});

/**
 * A site with two languages, two menus, a location, content types and taxonomies.
 */
function fullSite(): void
{
    $item = static fn (int $id, string $url, int $parent = 0, int $order = 1, array $more = []): object => (object) ($more + ['ID' => $id, 'url' => $url, 'menu_item_parent' => (string) $parent, 'menu_order' => $order, 'title' => 'Item '.$id, 'target' => '', 'classes' => ['', 'big', 12], 'type' => 'custom', 'object' => 'custom', 'object_id' => (string) $id]);
    $type = static function (string $name, mixed $rewrite, array $more = []): object {
        return (object) ($more + ['name' => $name, 'label' => ucfirst($name), 'rest_base' => '', 'rest_namespace' => '', 'hierarchical' => false, 'has_archive' => false, 'rewrite' => $rewrite]);
    };

    foreach (['get_current_blog_id' => 1, 'home_url' => 'https://cms.example.com', 'get_locale' => 'fr_FR', 'wp_timezone_string' => 'UTC', 'is_multisite' => true] as $function => $value) {
        Functions\when($function)->justReturn($value);
    }
    Functions\when('get_bloginfo')->returnArg();
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => ['date_format' => 'd/m/Y', 'time_format' => 'H:i', 'permalink_structure' => '/%postname%/', 'page_for_posts' => '4'][$name] ?? $default);
    Functions\when('wp_get_nav_menus')->justReturn([(object) ['term_id' => 8, 'slug' => 'z-footer', 'name' => 'Footer'], (object) ['term_id' => 5, 'slug' => 'a-main', 'name' => 'Main']]);
    Functions\when('wp_get_nav_menu_items')->alias(static fn (int $menu): array|false => $menu !== 5 ? false : [
        $item(11, 'https://cms.example.com/shop/?sort=new', 0, 2, ['target' => '_blank', 'type' => 'post_type', 'object' => 'page']),
        $item(10, 'https://cms.example.com', 0, 1),
        $item(12, 'https://cms.example.com/fr/boutique/', 11, 3),
        $item(13, 'https://elsewhere.example.org/x', 0, 4),
        $item(14, '#anchor', 0, 5),
    ]);
    Functions\when('get_nav_menu_locations')->justReturn(['primary' => 5, 'gone' => 99]);
    Functions\when('get_registered_nav_menus')->justReturn(['primary' => 'Primary', 'gone' => 'Gone', 'empty' => 'Empty']);
    Functions\when('wp_get_nav_menu_object')->alias(static function (int $id): WP_Term|false {
        if ($id !== 5) {
            return false;
        }

        $menu = Mockery::mock('WP_Term');
        $menu->slug = 'a-main';

        return $menu;
    });
    Functions\when('get_post_types')->alias(static fn (array $args, string $output): array => [
        'product' => $type('product', ['slug' => 'shop'], ['rest_base' => 'products', 'rest_namespace' => 'shop/v1', 'has_archive' => 'shop']),
        'page' => $type('page', false, ['hierarchical' => true]),
        'event' => $type('event', ['with_front' => false]),
    ]);
    Functions\when('get_taxonomies')->alias(static fn (): array => [
        'topic' => $type('topic', ['slug' => 'topics'], ['rest_base' => 'topics', 'object_type' => [3 => 'post'], 'hierarchical' => true]),
        'brand' => $type('brand', false, ['rest_namespace' => 'shop/v1', 'object_type' => ['product']]),
    ]);
}

function fullManifest(): array
{
    fullSite();
    $languages = twoLanguages();

    return (new SiteManifest(bilingualUrls($languages), Origin::fromUrl('https://www.example.com'), $languages))->build();
}

it('lists the languages, each with its own front end', function (): void {
    $manifest = fullManifest();

    expect($manifest['languages'])->toBe([
        ['code' => 'en', 'wordpress_url' => 'https://cms.example.com/', 'url' => 'https://www.example.com/'],
        ['code' => 'fr', 'wordpress_url' => 'https://cms.example.com/fr/', 'url' => 'https://www.example.fr/fr/'],
        ['code' => 'de', 'wordpress_url' => null, 'url' => 'https://www.example.com'],
    ])->and($manifest['site'])->toBe([
        'id' => 1, 'name' => 'name', 'description' => 'description', 'url' => 'https://www.example.com', 'wordpress_url' => 'https://cms.example.com',
        'locale' => 'fr_FR', 'language' => 'fr', 'timezone' => 'UTC', 'date_format' => 'd/m/Y', 'time_format' => 'H:i', 'multisite' => true,
    ])->and($manifest['front_page'])->toBe(['show_on_front' => 'posts', 'page_on_front' => 0, 'page_for_posts' => 4, 'posts_per_page' => 10])
        ->and($manifest['permalink_structure'])->toBe('/%postname%/');
});

it('gives the menus as trees, sorted, with a path only for the pages of the site', function (): void {
    $menus = fullManifest()['menus'];

    expect(array_column($menus, 'slug'))->toBe(['a-main', 'z-footer'])
        ->and($menus[1])->toBe(['id' => 8, 'slug' => 'z-footer', 'name' => 'Footer', 'items' => []])
        ->and(array_column($menus[0]['items'], 'id'))->toBe([10, 11, 13, 14])
        ->and(array_column($menus[0]['items'], 'path'))->toBe(['/', '/shop/?sort=new', null, null])
        ->and($menus[0]['items'][1])->toBe([
            'id' => 11, 'title' => 'Item 11', 'url' => 'https://www.example.com/shop/?sort=new', 'path' => '/shop/?sort=new', 'target' => '_blank', 'classes' => ['big'], 'type' => 'post_type', 'object' => 'page', 'object_id' => 11,
            'children' => [['id' => 12, 'title' => 'Item 12', 'url' => 'https://www.example.com/fr/boutique/', 'path' => '/fr/boutique/', 'target' => '', 'classes' => ['big'], 'type' => 'custom', 'object' => 'custom', 'object_id' => 12, 'children' => []]],
        ])
        ->and($menus[0]['items'][2]['url'])->toBe('https://elsewhere.example.org/x')
        ->and($menus[0]['items'][3]['url'])->toBe('#anchor');
});

it('counts the front end of a language as a page of the site', function (): void {
    fullSite();
    Functions\when('wp_get_nav_menu_items')->justReturn([(object) ['ID' => 1, 'url' => 'https://www.example.fr/fr/a/']]);
    $languages = twoLanguages();

    $menus = (new SiteManifest(bilingualUrls($languages), Origin::fromUrl('https://www.example.com'), $languages))->build()['menus'];

    expect($menus[0]['items'][0]['path'])->toBe('/fr/a/');
});

it('says which menu each location shows', function (): void {
    expect(fullManifest()['locations'])->toBe([
        ['location' => 'empty', 'description' => 'Empty', 'menu' => null],
        ['location' => 'gone', 'description' => 'Gone', 'menu' => null],
        ['location' => 'primary', 'description' => 'Primary', 'menu' => 'a-main'],
    ]);
});

it('lists the content types and taxonomies with their REST routes', function (): void {
    $manifest = fullManifest();

    expect($manifest['post_types'])->toBe([
        ['name' => 'event', 'label' => 'Event', 'rest_base' => 'event', 'rest_namespace' => 'wp/v2', 'hierarchical' => false, 'has_archive' => false, 'rewrite_slug' => 'event'],
        ['name' => 'page', 'label' => 'Page', 'rest_base' => 'page', 'rest_namespace' => 'wp/v2', 'hierarchical' => true, 'has_archive' => false, 'rewrite_slug' => 'page'],
        ['name' => 'product', 'label' => 'Product', 'rest_base' => 'products', 'rest_namespace' => 'shop/v1', 'hierarchical' => false, 'has_archive' => 'shop', 'rewrite_slug' => 'shop'],
    ])->and($manifest['taxonomies'])->toBe([
        ['name' => 'brand', 'label' => 'Brand', 'rest_base' => 'brand', 'rest_namespace' => 'shop/v1', 'hierarchical' => false, 'post_types' => ['product'], 'rewrite_slug' => 'brand'],
        ['name' => 'topic', 'label' => 'Topic', 'rest_base' => 'topics', 'rest_namespace' => 'wp/v2', 'hierarchical' => true, 'post_types' => ['post'], 'rewrite_slug' => 'topics'],
    ]);
});
