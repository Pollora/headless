<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\RequestTarget;

it('makes the path relative to the directory WordPress lives in', function (string $uri, string $expected): void {
    expect(RequestTarget::fromUri($uri, 'GET', '/wp')->path)->toBe($expected);
})->with([
    ['/wp/blog/hello?x=1', '/blog/hello'],
    ['/wp', '/'],
    ['/wp/', '/'],
    ['/wpx/blog', '/wpx/blog'],
    ['/other', '/other'],
]);

it('leaves the path alone without a base path', function (): void {
    expect(RequestTarget::fromUri('/wp/blog', 'GET', '')->path)->toBe('/wp/blog')
        ->and(RequestTarget::fromUri('/wp/blog', 'GET', '/')->path)->toBe('/wp/blog');
});
