<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Cache\Etag;
use Pollora\Headless\Domain\Cache\RestCachePolicy;

it('hashes a body into a strong ETag', function (): void {
    expect(Etag::forBody('{"a":1}'))->toBe('"'.md5('{"a":1}').'"');
});

it('matches the validators of a conditional request', function (?string $header, bool $expected): void {
    expect(Etag::matches($header, '"abc"'))->toBe($expected);
})->with([
    ['"abc"', true],
    ['W/"abc"', true],
    ['"zzz", "abc"', true],
    ['*', true],
    ['"zzz"', false],
    ['', false],
    [null, false],
]);

it('marks an anonymous successful GET on a public namespace', function (): void {
    expect((new RestCachePolicy)->cacheControl('GET', 200, false, false, '/wp/v2/posts'))
        ->toBe('public, max-age=60, s-maxage=300, stale-while-revalidate=60')
        ->and((new RestCachePolicy(10, 20, 30))->cacheControl('head', 200, false, false, '/wp/v2/posts/12'))
        ->toBe('public, max-age=10, s-maxage=20, stale-while-revalidate=30');
});

it('never marks what depends on who asks, or what is not a success', function (string $method, int $status, bool $user, bool $authorization, string $route): void {
    expect((new RestCachePolicy)->cacheControl($method, $status, $user, $authorization, $route))->toBeNull();
})->with([
    'a write' => ['POST', 200, false, false, '/wp/v2/posts'],
    'an error' => ['GET', 404, false, false, '/wp/v2/posts/9'],
    'a logged-in user' => ['GET', 200, true, false, '/wp/v2/posts'],
    'an Authorization header' => ['GET', 200, false, true, '/wp/v2/posts'],
    'another namespace' => ['GET', 200, false, false, '/acme/v1/things'],
    'a look-alike namespace' => ['GET', 200, false, false, '/wp/v2x/posts'],
    'the me route' => ['GET', 200, false, false, '/wp/v2/users/me'],
    'the settings' => ['GET', 200, false, false, '/wp/v2/settings'],
    'the search' => ['GET', 200, false, false, '/wp/v2/search'],
]);
