<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Seo\SeoMapper;

/*
 * Every value a SEO plugin gives lands under its own key of the common shape.
 */

/** @return array<string, string> the social keys of Yoast and All in One SEO, one value each */
function shapeSocialJson(): array
{
    $json = [];

    foreach (['og_type', 'og_title', 'og_description', 'og_url', 'og_site_name', 'og_locale', 'twitter_card', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_site', 'twitter_creator'] as $key) {
        $json[$key] = 'value of '.$key;
    }

    return $json;
}

function shapeExpected(string $source, array $over = []): array
{
    return array_replace([
        'source' => $source,
        'title' => null,
        'description' => null,
        'robots' => ['index' => true, 'follow' => true, 'directives' => []],
        'canonical' => null,
        'open_graph' => ['type' => null, 'title' => null, 'description' => null, 'url' => null, 'site_name' => null, 'locale' => null, 'images' => []],
        'twitter' => ['card' => null, 'title' => null, 'description' => null, 'image' => null, 'site' => null, 'creator' => null],
        'json_ld' => [],
        'breadcrumbs' => [],
    ], $over);
}

function shapeSocialExpected(): array
{
    return [
        'open_graph' => ['type' => 'value of og_type', 'title' => 'value of og_title', 'description' => 'value of og_description', 'url' => 'value of og_url', 'site_name' => 'value of og_site_name', 'locale' => 'value of og_locale', 'images' => []],
        'twitter' => ['card' => 'value of twitter_card', 'title' => 'value of twitter_title', 'description' => 'value of twitter_description', 'image' => 'value of twitter_image', 'site' => 'value of twitter_site', 'creator' => 'value of twitter_creator'],
    ];
}

it('puts every value of Yoast SEO in its place', function (): void {
    $seo = SeoMapper::yoast([...shapeSocialJson(), 'title' => 'T', 'description' => 'D', 'canonical' => 'C']);

    expect($seo->toArray())->toBe(shapeExpected('yoast', ['title' => 'T', 'description' => 'D', 'canonical' => 'C', ...shapeSocialExpected()]));
});

it('puts every value of All in One SEO in its place', function (): void {
    $json = shapeSocialJson();
    unset($json['twitter_image']);
    $seo = SeoMapper::aioseo([...$json, 'title' => 'T', 'description' => 'D', 'canonical_url' => 'C', 'twitter_image_url' => 'value of twitter_image', 'og_image_url' => 'I', 'og_image_width' => '800', 'og_image_height' => 600]);
    $social = shapeSocialExpected();
    $social['open_graph']['images'] = [['url' => 'I', 'width' => 800, 'height' => 600, 'alt' => null]];

    expect($seo->toArray())->toBe(shapeExpected('aioseo', ['title' => 'T', 'description' => 'D', 'canonical' => 'C', ...$social]));
});

it('puts every value of SEOPress in its place', function (): void {
    $seo = SeoMapper::seopress([
        'title' => 'T', 'description' => 'D', 'canonical' => 'C',
        'og' => ['type' => 'a', 'title' => 'b', 'description' => 'c', 'url' => 'd', 'site_name' => 'e', 'image' => 'f'],
        'twitter' => ['card' => 'g', 'title' => 'h', 'description' => 'i', 'image' => 'j'],
    ]);

    expect($seo->toArray())->toBe(shapeExpected('seopress', [
        'title' => 'T', 'description' => 'D', 'canonical' => 'C',
        'open_graph' => ['type' => 'a', 'title' => 'b', 'description' => 'c', 'url' => 'd', 'site_name' => 'e', 'locale' => null, 'images' => [['url' => 'f', 'width' => null, 'height' => null, 'alt' => null]]],
        'twitter' => ['card' => 'g', 'title' => 'h', 'description' => 'i', 'image' => 'j', 'site' => null, 'creator' => null],
    ]));
});

it('puts every value of a printed head in its place', function (): void {
    $meta = '';

    foreach (['og:type', 'og:title', 'og:description', 'og:url', 'og:site_name', 'og:locale', 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:site', 'twitter:creator'] as $name) {
        // The first of two tags with one name counts
        $meta .= '<meta property="'.$name.'" content="'.$name.' first"><meta property="'.$name.'" content="'.$name.' second">';
    }

    $meta .= '<meta property="og:image" content="one"><meta property="og:image:width" content="10"><meta property="og:image:height" content="20"><meta property="og:image:alt" content="first picture">'
        .'<meta property="og:image" content="two">';
    $seo = SeoMapper::headHtml($meta.'<meta name="description" content="D">', 'other');

    expect($seo->toArray())->toBe(shapeExpected('other', [
        'description' => 'D',
        'open_graph' => ['type' => 'og:type first', 'title' => 'og:title first', 'description' => 'og:description first', 'url' => 'og:url first', 'site_name' => 'og:site_name first', 'locale' => 'og:locale first', 'images' => [
            ['url' => 'one', 'width' => 10, 'height' => 20, 'alt' => 'first picture'],
            ['url' => 'two', 'width' => null, 'height' => null, 'alt' => null],
        ]],
        'twitter' => ['card' => 'twitter:card first', 'title' => 'twitter:title first', 'description' => 'twitter:description first', 'image' => 'twitter:image first', 'site' => 'twitter:site first', 'creator' => 'twitter:creator first'],
    ]));
});

it('reads an empty answer of any plugin as nothing at all', function (string $source): void {
    $seo = match ($source) {
        'yoast' => SeoMapper::yoast([]),
        'aioseo' => SeoMapper::aioseo([]),
        'seopress' => SeoMapper::seopress([]),
        'rankmath' => SeoMapper::headHtml(''),
    };

    expect($seo->toArray())->toBe(shapeExpected($source));
})->with(['yoast', 'aioseo', 'seopress', 'rankmath']);

it('takes a text of blanks, or something that is not text, as nothing', function (mixed $value): void {
    expect(SeoMapper::yoast(['description' => $value])->description)->toBeNull();
})->with(['', "  \n ", 5, [[]]]);

it('keeps the robots directives that say something', function (): void {
    expect(SeoMapper::yoast(['robots' => ['index' => 'index', 'follow' => 'follow', 'a' => '', 'b' => 5, 'c' => 'noarchive']])->directives)->toBe(['noarchive'])
        ->and(SeoMapper::aioseo(['robots' => 'index, follow,, noindex ,nofollow, noarchive'])->directives)->toBe(['noarchive'])
        ->and(SeoMapper::aioseo(['robots' => ['noarchive', ['nested'], 'nosnippet']])->directives)->toBe(['noarchive', 'nosnippet'])
        ->and(SeoMapper::headHtml('<meta name="robots" content="INDEX, follow,, noindex, nofollow, NoArchive">')->directives)->toBe(['noarchive']);
});

it('takes the robots of a head whatever their case', function (): void {
    $seo = SeoMapper::headHtml('<meta name="robots" content="NOINDEX, NOFOLLOW">');

    expect($seo->index)->toBeFalse()->and($seo->follow)->toBeFalse();
});

it('reads what SEOPress calls yes, and nothing else', function (mixed $value, bool $set): void {
    $seo = SeoMapper::seopress(['robots' => ['noindex' => $value, 'nofollow' => $value]]);

    expect($seo->index)->toBe(! $set)->and($seo->follow)->toBe(! $set);
})->with([[true, true], [1, true], ['1', true], ['yes', true], ['true', true], [false, false], [0, false], [2, false], ['0', false], ['no', false], [null, false]]);

it('leaves out of SEOPress breadcrumbs what has no name', function (): void {
    $seo = SeoMapper::seopress(['breadcrumbs' => ['junk', ['url' => 'x'], ['name' => 5], ['name' => 'Home']]]);

    expect($seo->breadcrumbs)->toBe([['label' => 'Home', 'url' => null]]);
});

it('lists the nodes of a graph that are nodes', function (string $source): void {
    $json = ['schema' => ['@graph' => ['junk', 3 => ['@type' => 'WebPage']]]];
    $seo = $source === 'yoast' ? SeoMapper::yoast($json) : SeoMapper::aioseo($json);

    expect($seo->jsonLd)->toBe([['@type' => 'WebPage']]);
})->with(['yoast', 'aioseo']);

it('collects the structured data of every script, alone or in a graph', function (): void {
    $script = static fn (string $json): string => "<script type='application/ld+json'>\n".$json."\n</script>";
    $seo = SeoMapper::headHtml($script('{"@type":"Organization"}').$script('{"@graph":["junk",{"@type":"WebPage"}]}').$script('"text"'));

    expect($seo->jsonLd)->toBe([['@type' => 'Organization'], ['@type' => 'WebPage']]);
});

it('decodes the entities of a title, a canonical address and a meta, and trims the title', function (): void {
    $seo = SeoMapper::headHtml('<title>  Tom&apos;s &quot;page&quot;  </title><link rel="canonical" href="https://a.test/?a=1&amp;b=2"><meta name="description" content="Tom&apos;s">');

    expect($seo->title)->toBe('Tom\'s "page"')
        ->and($seo->canonical)->toBe('https://a.test/?a=1&b=2')
        ->and($seo->description)->toBe("Tom's");
});

it('has no title when the head has an empty one', function (): void {
    expect(SeoMapper::headHtml('<title>  </title>')->title)->toBeNull();
});

it('ignores a meta without a name or without a content', function (): void {
    $seo = SeoMapper::headHtml('<meta charset="utf-8"><meta name="description"><meta content="orphan"><meta name="twitter:card" content="summary">');

    expect($seo->description)->toBeNull()->and($seo->twitter['card'])->toBe('summary');
});

it('keeps the images of a social card that have an address, with the measures that are numbers', function (): void {
    $seo = SeoMapper::yoast(['og_image' => [
        'junk',
        ['width' => 1],
        ['url' => ''],
        ['url' => 5],
        ['url' => 'a', 'width' => 'wide', 'height' => '30', 'alt' => 'A'],
        ['url' => 'b', 'width' => '40', 'height' => 'tall', 'alt' => ' '],
    ]]);

    expect($seo->openGraph['images'])->toBe([
        ['url' => 'a', 'width' => null, 'height' => 30, 'alt' => 'A'],
        ['url' => 'b', 'width' => 40, 'height' => null, 'alt' => null],
    ]);
});

it('takes the address of a Twitter image given as an object', function (): void {
    expect(SeoMapper::yoast(['twitter_image' => ['url' => 'u']])->twitter['image'])->toBe('u')
        ->and(SeoMapper::yoast(['twitter_image' => ['width' => 1]])->twitter['image'])->toBeNull();
});

it('reads breadcrumbs from the item of a list, whichever way it names its page', function (): void {
    $seo = SeoMapper::yoast(['schema' => ['@graph' => [
        ['@type' => 'WebPage', 'itemListElement' => [['name' => 'not a breadcrumb']]],
        ['@type' => 'BreadcrumbList', 'itemListElement' => 'broken'],
        ['@type' => ['BreadcrumbList'], 'itemListElement' => [
            'junk',
            ['item' => ['name' => 'Home', '@id' => 'https://a.test/']],
            ['name' => 'Blog', 'item' => 'https://a.test/blog/'],
            ['item' => 'https://a.test/nameless/'],
            ['name' => 'Here'],
        ]],
    ]]]);

    expect($seo->breadcrumbs)->toBe([
        ['label' => 'Home', 'url' => 'https://a.test/'],
        ['label' => 'Blog', 'url' => 'https://a.test/blog/'],
        ['label' => 'Here', 'url' => null],
    ]);
});
