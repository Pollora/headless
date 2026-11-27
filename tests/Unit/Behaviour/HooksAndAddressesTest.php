<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Cache\Etag;
use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Plugin\PluginFilter;
use Pollora\Headless\Domain\Search\SearchQuery;
use Pollora\Headless\Infrastructure\Language\PolylangLanguages;
use Pollora\Headless\Infrastructure\Language\WpmlLanguages;
use Pollora\Headless\Infrastructure\WordPress\CleanUp;
use Pollora\Headless\Infrastructure\WordPress\FrontLinks;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

it('cleans the head early, taking each tag off where WordPress put it', function (): void {
    Actions\expectAdded('init')->once()->with(Mockery::type('array'), 1);
    $removed = [];
    Functions\when('remove_action')->alias(static function (string $hook, string $callback, int $priority = 10) use (&$removed): void {
        $removed[] = [$hook, $callback, $priority];
    });

    $module = new CleanUp(true, false);
    $module->register(new WordPressHooks);
    $module->cleanHead();

    expect($removed)->toBe([
        ['wp_head', 'wp_generator', 10],
        ['wp_head', 'rsd_link', 10],
        ['wp_head', 'wlwmanifest_link', 10],
        ['wp_head', 'wp_shortlink_wp_head', 10],
        ['wp_head', 'rest_output_link_wp_head', 10],
        ['wp_head', 'feed_links', 2],
        ['wp_head', 'feed_links_extra', 3],
        ['wp_head', 'wp_oembed_add_discovery_links', 4],
        ['wp_head', 'wp_oembed_add_discovery_links', 10],
        ['wp_head', 'print_emoji_detection_script', 7],
        ['wp_print_styles', 'print_emoji_styles', 10],
        ['template_redirect', 'rest_output_link_header', 11],
        ['template_redirect', 'wp_shortlink_header', 11],
    ]);
});

it('takes from each link filter the arguments it reads, and rewrites content after WordPress rendered it', function (): void {
    foreach (['post_link', 'page_link', 'post_type_link'] as $filter) {
        Filters\expectAdded($filter)->once()->with(Mockery::type('array'), 10, 2);
    }

    foreach (['term_link', 'post_type_archive_link', 'author_link'] as $filter) {
        Filters\expectAdded($filter)->once()->with(Mockery::type('array'), 10, 1);
    }

    Filters\expectAdded('the_content')->once()->with(Mockery::type('array'), 20, 1);

    $urls = Mockery::mock(FrontUrls::class);
    (new FrontLinks($urls, new ContentLinks($urls)))->register(new WordPressHooks);
});

it('asks the language of the post a link is for, by object or by number', function (mixed $post, ?string $language): void {
    $urls = Mockery::mock(FrontUrls::class);
    $urls->shouldReceive('toFront')->once()->with('url', $language)->andReturn('front');
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('ofPost')->andReturnUsing(static fn (int $id): string => 'language of '.$id);

    expect((new FrontLinks($urls, null, $languages))->postLink('url', $post))->toBe('front');
})->with([
    [new WP_Post(['ID' => 4]), 'language of 4'],
    ['12', 'language of 12'],
    [1, 'language of 1'],
    [0, null],
    [null, null],
    ['slug', null],
]);

it('asks no language without a multilingual plugin', function (): void {
    $urls = Mockery::mock(FrontUrls::class);
    $urls->shouldReceive('toFront')->once()->with('url', null)->andReturn('front');

    expect((new FrontLinks($urls, null))->postLink('url', 4))->toBe('front');
});

it('keeps the links of WordPress\'s own directories, below its folder', function (): void {
    $urls = Mockery::mock(FrontUrls::class);
    $urls->shouldReceive('toFront')->andReturnUsing(static fn (string $url): string => 'front:'.$url);
    $links = new ContentLinks($urls, basePath: '/wp/');
    $kept = '<a href="/wp/wp-content/a.pdf">a</a><a href="/wp/graphql">g</a>';

    expect($links->rewrite($kept))->toBe($kept)
        ->and($links->rewrite('<a href="/wp/wp-contents">b</a>'))->toBe('<a href="front:/wp/wp-contents">b</a>');
});

it('compares the ETags a client holds whatever the blanks around them', function (string $header, bool $held): void {
    expect(Etag::matches($header, '"a"'))->toBe($held);
})->with([[' "a" ', true], ['"b", W/"a"', true], [' * ', true], ['  ', false], ['"b"', false]]);

it('keeps a plugin by its folder however deep its file is', function (): void {
    expect((new PluginFilter(['acf']))->keeps('acf/pro/acf.php'))->toBeTrue()
        ->and(PluginFilter::isRestRequest('http://:80'))->toBeFalse();
});

it('searches from two characters, in a list of the types asked for, on a page from 1', function (): void {
    $query = static fn (string $term, string $types = '', int $page = 1, int $perPage = 0): ?SearchQuery => SearchQuery::fromRequest($term, $types, null, $page, $perPage, 50, ['post', 'page', 'event'], []);

    expect($query('ab'))->not->toBeNull()
        ->and($query('a'))->toBeNull()
        ->and($query('ab', 'x, event,,page')->types)->toBe(['event', 'page'])
        ->and($query('ab', ',,page')->types)->toBe(['page'])
        ->and($query('ab', page: 0)->page)->toBe(1)
        ->and($query('ab', page: -4)->page)->toBe(1)
        ->and($query('ab', perPage: -1)->perPage)->toBe(10)
        ->and($query('ab', perPage: 1)->perPage)->toBe(1);
});

it('asks WPML for its languages, past the "all" of the administration', function (): void {
    $asked = new ArrayObject;
    Functions\when('get_post_type')->justReturn('page');
    Functions\when('home_url')->justReturn('https://cms.example.com/');
    Functions\when('apply_filters')->alias(static function (string $hook, mixed ...$args) use ($asked): mixed {
        $asked[$hook] = $args;

        return match ($hook) {
            'wpml_active_languages' => ['en' => [], 'fr' => []],
            'wpml_element_trid' => 33,
            'wpml_get_element_translations' => ['en' => (object) ['element_id' => '4'], 'fr' => (object) ['element_id' => 'x'], 'de' => (object) [], 'es' => 'junk'],
            default => null,
        };
    });
    $wpml = new WpmlLanguages;

    expect($wpml->all())->toBe(['en', 'fr'])
        ->and($asked['wpml_active_languages'])->toBe([null, ['skip_missing' => 0]])
        ->and($wpml->translations(4))->toBe(['en' => 4])
        ->and($asked['wpml_element_trid'])->toBe([null, 4, 'post_page'])
        ->and($asked['wpml_get_element_translations'])->toBe([null, 33, 'post_page']);
});

it('takes no empty or "all" answer of WPML for a language or an address', function (mixed $answer): void {
    Functions\when('home_url')->justReturn('https://cms.example.com/');
    Functions\when('apply_filters')->alias(static fn (string $hook): mixed => $hook === 'wpml_post_language_details' ? ['language_code' => $answer] : $answer);
    $wpml = new WpmlLanguages;

    expect([$wpml->current(), $wpml->ofPost(1), $wpml->homeUrl('fr')])->toBe([null, null, null]);
})->with(['', 5, false]);

it('has no current language when WPML shows all of them', function (): void {
    Functions\when('apply_filters')->justReturn('all');

    expect((new WpmlLanguages)->current())->toBeNull();
});

it('asks Polylang for the slugs of its languages', function (): void {
    Functions\expect('pll_languages_list')->once()->with(['fields' => 'slug'])->andReturn([2 => 7, 3 => 'fr']);

    expect((new PolylangLanguages)->all())->toBe(['fr']);
});

it('takes no empty answer of Polylang for a language', function (): void {
    Functions\when('pll_current_language')->justReturn('');
    Functions\when('pll_get_post_language')->justReturn('');
    Functions\when('pll_home_url')->justReturn('');
    $polylang = new PolylangLanguages;

    expect([$polylang->current(), $polylang->ofPost(1), $polylang->homeUrl('fr')])->toBe([null, null, null]);
});
