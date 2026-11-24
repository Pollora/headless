<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;

function mapper(): UrlMapper
{
    return new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'));
}

it('moves a URL of the WordPress site to the front end, keeping path, query and fragment', function (): void {
    expect(mapper()->toFront('https://cms.example.com/blog/hello/?page=2#comments'))->toBe('https://www.example.com/blog/hello/?page=2#comments');
});

it('maps the bare origin', function (): void {
    expect(mapper()->toFront('https://cms.example.com'))->toBe('https://www.example.com');
});

it('leaves alone what is not on the WordPress host', function (string $url): void {
    expect(mapper()->toFront($url))->toBe($url);
})->with([
    'another site' => 'https://other.example.org/page',
    'relative' => '/blog/hello',
    'mail' => 'mailto:me@example.com',
    'not a URL' => 'just text',
    'another port' => 'https://cms.example.com:8443/page',
]);
