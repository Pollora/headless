<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoMapper;

function yoastJson(): array
{
    return [
        'title' => 'Hello - My site',
        'description' => 'A description',
        'robots' => ['index' => 'index', 'follow' => 'follow', 'max-snippet' => 'max-snippet:-1', 'max-image-preview' => 'max-image-preview:large'],
        'canonical' => 'https://cms.example.com/hello/',
        'og_locale' => 'en_US', 'og_type' => 'article', 'og_title' => 'Hello', 'og_description' => 'OG description', 'og_url' => 'https://cms.example.com/hello/', 'og_site_name' => 'My site',
        'og_image' => [['url' => 'https://cms.example.com/wp-content/uploads/a.jpg', 'width' => 1200, 'height' => 630, 'type' => 'image/jpeg']],
        'twitter_card' => 'summary_large_image', 'twitter_title' => 'Hello T', 'twitter_site' => '@site', 'twitter_misc' => ['Written by' => 'x'],
        'schema' => ['@graph' => [
            ['@type' => 'WebPage', '@id' => 'https://cms.example.com/hello/#webpage', 'url' => 'https://cms.example.com/hello/'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cms.example.com/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Hello'],
            ]],
        ]],
    ];
}

it('reads the head JSON of Yoast SEO', function (): void {
    $seo = SeoMapper::yoast(yoastJson())->toArray();

    expect($seo['source'])->toBe('yoast')
        ->and($seo['title'])->toBe('Hello - My site')
        ->and($seo['robots'])->toBe(['index' => true, 'follow' => true, 'directives' => ['max-snippet:-1', 'max-image-preview:large']])
        ->and($seo['canonical'])->toBe('https://cms.example.com/hello/')
        ->and($seo['open_graph'])->toMatchArray(['type' => 'article', 'title' => 'Hello', 'site_name' => 'My site', 'locale' => 'en_US'])
        ->and($seo['open_graph']['images'])->toBe([['url' => 'https://cms.example.com/wp-content/uploads/a.jpg', 'width' => 1200, 'height' => 630, 'alt' => null]])
        ->and($seo['twitter'])->toMatchArray(['card' => 'summary_large_image', 'title' => 'Hello T', 'site' => '@site'])
        ->and($seo['json_ld'])->toHaveCount(2)
        ->and($seo['breadcrumbs'])->toBe([['label' => 'Home', 'url' => 'https://cms.example.com/'], ['label' => 'Hello', 'url' => null]]);
});

it('reads noindex and nofollow from Yoast', function (): void {
    $seo = SeoMapper::yoast(['robots' => ['index' => 'noindex', 'follow' => 'nofollow']]);

    expect($seo->index)->toBeFalse()->and($seo->follow)->toBeFalse()->and($seo->directives)->toBe([]);
});

it('reads an empty answer as a page with nothing to say, and no field as null', function (): void {
    $seo = SeoMapper::yoast([]);

    expect($seo->index)->toBeTrue()->and($seo->title)->toBeNull()->and($seo->canonical)->toBeNull()
        ->and($seo->jsonLd)->toBe([])->and($seo->breadcrumbs)->toBe([])->and($seo->openGraph['images'])->toBe([]);
});

it('reads the head JSON of All in One SEO, whatever keys are there', function (): void {
    $seo = SeoMapper::aioseo([
        'title' => 'A', 'description' => 'D', 'canonical_url' => 'https://cms.example.com/a/', 'robots' => 'noindex, max-image-preview:large',
        'og_title' => 'OG', 'og_type' => 'article', 'og_image_url' => 'https://cms.example.com/i.jpg', 'og_image_width' => '800', 'twitter_card' => 'summary', 'twitter_image_url' => 'https://cms.example.com/t.jpg',
        'schema' => ['@graph' => [['@type' => 'WebPage']]],
    ]);

    expect($seo->source)->toBe('aioseo')
        ->and($seo->index)->toBeFalse()->and($seo->follow)->toBeTrue()->and($seo->directives)->toBe(['max-image-preview:large'])
        ->and($seo->canonical)->toBe('https://cms.example.com/a/')
        ->and($seo->openGraph['images'][0])->toBe(['url' => 'https://cms.example.com/i.jpg', 'width' => 800, 'height' => null, 'alt' => null])
        ->and($seo->twitter['image'])->toBe('https://cms.example.com/t.jpg')
        ->and($seo->jsonLd)->toBe([['@type' => 'WebPage']])
        ->and(SeoMapper::aioseo(['robots' => ['noindex', 'nofollow']])->follow)->toBeFalse()
        ->and(SeoMapper::aioseo([])->index)->toBeTrue();
});

it('reads the answer of SEOPress', function (): void {
    $seo = SeoMapper::seopress([
        'title' => 'S', 'description' => 'D', 'canonical' => 'https://cms.example.com/s/', 'robots' => ['noindex' => 'yes', 'nofollow' => false],
        'og' => ['title' => 'OT', 'image' => 'https://cms.example.com/o.jpg', 'type' => 'website'], 'twitter' => ['card' => 'summary', 'image' => 'https://cms.example.com/t.jpg'],
        'breadcrumbs' => [['name' => 'Home', 'url' => 'https://cms.example.com/'], ['label' => 'S'], 'junk'],
    ]);

    expect($seo->source)->toBe('seopress')
        ->and($seo->index)->toBeFalse()->and($seo->follow)->toBeTrue()
        ->and($seo->openGraph)->toMatchArray(['title' => 'OT', 'type' => 'website'])
        ->and($seo->openGraph['images'][0]['url'])->toBe('https://cms.example.com/o.jpg')
        ->and($seo->breadcrumbs)->toBe([['label' => 'Home', 'url' => 'https://cms.example.com/'], ['label' => 'S', 'url' => null]]);
});

it('reads the head Rank Math prints', function (): void {
    $head = '<title>Rank &amp; Hello</title><meta name="description" content="Desc &quot;quoted&quot;"/><meta name="robots" content="follow, noindex, max-snippet:-1"/>'
        .'<link rel="canonical" href="https://cms.example.com/r/" /><meta property="og:type" content="article"/><meta property="og:image" content="https://cms.example.com/a.jpg"/>'
        .'<meta property="og:image:width" content="640"/><meta name="twitter:card" content="summary_large_image"/>'
        .'<script type="application/ld+json" class="rank-math-schema">{"@context":"https://schema.org","@graph":[{"@type":"WebPage","url":"https://cms.example.com/r/"}]}</script>';
    $seo = SeoMapper::headHtml($head);

    expect($seo->source)->toBe('rankmath')
        ->and($seo->title)->toBe('Rank & Hello')
        ->and($seo->description)->toBe('Desc "quoted"')
        ->and($seo->index)->toBeFalse()->and($seo->follow)->toBeTrue()->and($seo->directives)->toBe(['max-snippet:-1'])
        ->and($seo->canonical)->toBe('https://cms.example.com/r/')
        ->and($seo->openGraph['images'])->toBe([['url' => 'https://cms.example.com/a.jpg', 'width' => 640, 'height' => null, 'alt' => null]])
        ->and($seo->twitter['card'])->toBe('summary_large_image')
        ->and($seo->jsonLd)->toBe([['@type' => 'WebPage', 'url' => 'https://cms.example.com/r/']]);
});

it('reads a head with nothing in it', function (): void {
    $seo = SeoMapper::headHtml('<p>No head</p><script type="application/ld+json">not json</script>');

    expect($seo->title)->toBeNull()->and($seo->canonical)->toBeNull()->and($seo->jsonLd)->toBe([])->and($seo->index)->toBeTrue();
});

it('moves every address to the front end: canonical, og, images, structured data, breadcrumbs', function (): void {
    $map = static fn (string $url): string => str_replace('https://cms.example.com', 'https://www.example.com', $url);
    $seo = SeoMapper::yoast(yoastJson())->withUrls($map)->toArray();

    expect($seo['canonical'])->toBe('https://www.example.com/hello/')
        ->and($seo['open_graph']['url'])->toBe('https://www.example.com/hello/')
        ->and($seo['open_graph']['images'][0]['url'])->toBe('https://www.example.com/wp-content/uploads/a.jpg')
        ->and($seo['json_ld'][0]['@id'])->toBe('https://www.example.com/hello/#webpage')
        ->and($seo['json_ld'][1]['itemListElement'][0]['item'])->toBe('https://www.example.com/')
        ->and($seo['breadcrumbs'][0]['url'])->toBe('https://www.example.com/')
        ->and($seo['breadcrumbs'][1]['url'])->toBeNull()
        ->and($seo['title'])->toBe('Hello - My site');
});

it('has the same keys whichever plugin gave it', function (): void {
    $keys = static fn (Seo $seo): array => array_keys($seo->toArray());

    expect($keys(SeoMapper::yoast([])))->toBe($keys(SeoMapper::aioseo([])))->and($keys(SeoMapper::seopress([])))->toBe($keys(SeoMapper::headHtml('')))->and($keys(SeoMapper::yoast([])))->toBe(['source', 'title', 'description', 'robots', 'canonical', 'open_graph', 'twitter', 'json_ld', 'breadcrumbs']);
});

it('takes the title of Yoast\'s social card when it leaves the title out', function (): void {
    expect(SeoMapper::yoast(['og_title' => 'Hello - My site'])->title)->toBe('Hello - My site')
        ->and(SeoMapper::yoast(['title' => 'Real', 'og_title' => 'Other'])->title)->toBe('Real');
});
