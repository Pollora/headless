<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Exceptions\InvalidOriginException;
use Pollora\Headless\Domain\Front\Origin;

it('keeps the scheme, host and port of a URL, without its path', function (): void {
    expect(Origin::fromUrl('HTTPS://WWW.Example.com:8443/blog?x=1')->toString())->toBe('https://www.example.com:8443');
});

it('has no port in its string when none was given', function (): void {
    expect(Origin::fromUrl('https://example.com/')->toString())->toBe('https://example.com');
});

it('refuses a value that is not an absolute http(s) URL', function (string $value): void {
    Origin::fromUrl($value);
})->throws(InvalidOriginException::class)->with(['example.com', '/relative', 'ftp://example.com', 'mailto:me@example.com', '']);

it('compares hosts with the default port of the scheme', function (): void {
    expect(Origin::fromUrl('https://example.com')->sameHostAs(Origin::fromUrl('http://example.com:80')))->toBeFalse()
        ->and(Origin::fromUrl('https://example.com')->sameHostAs(Origin::fromUrl('https://example.com:443')))->toBeTrue()
        ->and(Origin::fromUrl('http://example.com')->sameHostAs(Origin::fromUrl('http://example.com:80')))->toBeTrue()
        ->and(Origin::fromUrl('https://a.example.com')->sameHostAs(Origin::fromUrl('https://b.example.com')))->toBeFalse();
});
