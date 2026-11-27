<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoProvider;
use Pollora\Headless\Infrastructure\Seo\InternalRest;
use Pollora\Headless\Infrastructure\Seo\SeoProviders;

/*
 * Reading each SEO plugin through the REST API, from inside WordPress.
 */

/**
 * The REST API of the site: what each route answers, and what was asked.
 *
 * @param  array<string, WP_REST_Response>  $answers
 * @param  ArrayObject<int, array{string, mixed, mixed}>  $asked  route, `url` and `_fields`
 */
function restAnswers(array $answers, ArrayObject $asked): void
{
    Functions\when('rest_do_request')->alias(static function (WP_REST_Request $request) use ($answers, $asked): WP_REST_Response {
        $asked->append([$request->get_route(), $request->get_param('url'), $request->get_param('_fields')]);

        return $answers[$request->get_route()] ?? new WP_REST_Response(['code' => 'rest_no_route'], 404);
    });
}

function seoOf(string $plugin): SeoProvider
{
    $provider = SeoProviders::from($plugin === 'yoast', $plugin === 'rankmath', $plugin === 'aioseo', $plugin === 'seopress');
    expect([$provider->name(), $provider->isActive()])->toBe([$plugin, true]);

    return $provider;
}

it('reads nothing from a route that fails or answers something else', function (WP_REST_Response $answer): void {
    restAnswers(['/x' => $answer], new ArrayObject);

    expect(InternalRest::get('/x'))->toBeNull()
        ->and(InternalRest::field('/x', 'title'))->toBeNull();
})->with([
    'an error, even with a body' => [new WP_REST_Response(['title' => 'forbidden'], 403)],
    'not an object' => [new WP_REST_Response('title', 200)],
]);

it('reads Yoast SEO from its field of the post or the term', function (): void {
    restAnswers([
        '/wp/v2/posts/4' => new WP_REST_Response(['yoast_head_json' => ['title' => 'Post title']]),
        '/wp/v2/categories/5' => new WP_REST_Response(['yoast_head_json' => ['title' => 'Term title']]),
    ], $asked = new ArrayObject);
    Functions\when('get_post')->justReturn(new WP_Post(['ID' => 4]));
    Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/posts/4');
    Functions\when('rest_get_route_for_term')->justReturn('/wp/v2/categories/5');

    $yoast = seoOf('yoast');

    expect($yoast->forPost(4)?->title)->toBe('Post title')
        ->and($yoast->forTerm(5, 'category')?->title)->toBe('Term title')
        ->and($asked->getArrayCopy())->toBe([['/wp/v2/posts/4', null, 'yoast_head_json'], ['/wp/v2/categories/5', null, 'yoast_head_json']]);
});

it('has nothing for a post that is gone, a term without a route, or a field that is not there', function (): void {
    restAnswers(['/wp/v2/posts/4' => new WP_REST_Response(['id' => 4])], $asked = new ArrayObject);
    Functions\when('get_post')->alias(static fn (int $id): ?WP_Post => $id === 4 ? new WP_Post(['ID' => 4]) : null);
    Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/posts/4');
    Functions\when('rest_get_route_for_term')->justReturn('');

    $aioseo = seoOf('aioseo');

    expect($aioseo->forPost(9))->toBeNull()
        ->and($aioseo->forTerm(5, 'category'))->toBeNull()
        ->and($aioseo->forPost(4))->toBeNull()
        ->and($asked->getArrayCopy())->toBe([['/wp/v2/posts/4', null, 'aioseo_head_json']]);
});

it('asks Rank Math for the head of the page on the WordPress host', function (): void {
    restAnswers(['/rankmath/v1/getHead' => new WP_REST_Response(['head' => '<title>From the head</title>'])], $asked = new ArrayObject);
    Functions\when('get_permalink')->justReturn('https://www.example.com/hello/');
    Functions\when('get_term_link')->justReturn('https://www.example.com/category/news/');
    Functions\when('home_url')->alias(static fn (string $path): string => 'https://cms.example.com'.$path);

    $rankMath = seoOf('rankmath');

    expect($rankMath->forPost(4)?->title)->toBe('From the head')
        ->and($rankMath->forTerm(5, 'category'))->toBeInstanceOf(Seo::class)
        ->and(array_column($asked->getArrayCopy(), 1))->toBe(['https://cms.example.com/hello/', 'https://cms.example.com/category/news/']);
});

it('has nothing from Rank Math for a page with no address, or an empty head', function (): void {
    restAnswers(['/rankmath/v1/getHead' => new WP_REST_Response(['head' => ''])], $asked = new ArrayObject);
    Functions\when('get_permalink')->alias(static fn (int $id): string|false => $id === 4 ? 'https://cms.example.com/a/' : false);
    Functions\when('get_term_link')->justReturn(new WP_Error('invalid_term'));
    Functions\when('home_url')->justReturn('https://cms.example.com/a/');

    $rankMath = seoOf('rankmath');

    expect($rankMath->forPost(9))->toBeNull()
        ->and($rankMath->forTerm(5, 'category'))->toBeNull()
        ->and($asked)->toHaveCount(0)
        ->and($rankMath->forPost(4))->toBeNull();
});

it('reads SEOPress from its routes', function (): void {
    restAnswers([
        '/seopress/v1/posts/4' => new WP_REST_Response(['data' => ['title' => 'Post']]),
        '/seopress/v1/terms/5' => new WP_REST_Response(['data' => ['title' => 'Term']]),
        '/seopress/v1/posts/6' => new WP_REST_Response(['data' => 'not data']),
    ], new ArrayObject);

    $seopress = seoOf('seopress');

    expect($seopress->forPost(4)?->title)->toBe('Post')
        ->and($seopress->forTerm(5, 'category')?->title)->toBe('Term')
        ->and($seopress->forPost(6))->toBeNull()
        ->and($seopress->forPost(7))->toBeNull();
});
