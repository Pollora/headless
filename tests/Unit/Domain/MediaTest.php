<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Media\FocalPoint;
use Pollora\Headless\Domain\Media\MediaKind;
use Pollora\Headless\Domain\Media\MediaUrls;

it('keeps the path after the uploads directory', function (): void {
    $urls = new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com');

    expect($urls->toCdn('https://cms.example.com/wp-content/uploads/2026/11/a.jpg'))->toBe('https://cdn.example.com/2026/11/a.jpg')
        ->and($urls->toCdn('http://cms.example.com/wp-content/uploads/2026/11/a.jpg?x=1'))->toBe('https://cdn.example.com/2026/11/a.jpg?x=1')
        ->and($urls->toCdn('https://cms.example.com/wp-content/uploads'))->toBe('https://cdn.example.com');
});

it('can serve the uploads under a path of the CDN', function (): void {
    $urls = new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com/media');

    expect($urls->toCdn('https://cms.example.com/wp-content/uploads/a.jpg'))->toBe('https://cdn.example.com/media/a.jpg');
});

it('leaves alone what is not an uploaded file', function (string $url): void {
    $urls = new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com');

    expect($urls->toCdn($url))->toBe($url);
})->with([
    'a page' => 'https://cms.example.com/about/',
    'a theme file' => 'https://cms.example.com/wp-content/themes/x/a.css',
    'another host' => 'https://other.example.org/wp-content/uploads/a.jpg',
    'a look-alike directory' => 'https://cms.example.com/wp-content/uploads-old/a.jpg',
    'not an address' => 'a.jpg',
    'nothing' => '',
]);

it('does nothing without an uploads address', function (): void {
    expect((new MediaUrls('', 'https://cdn.example.com'))->toCdn('https://cms.example.com/a.jpg'))->toBe('https://cms.example.com/a.jpg')
        ->and((new MediaUrls('', 'https://cdn.example.com'))->rewriteHtml('<img src="https://cms.example.com/a.jpg">'))->toBe('<img src="https://cms.example.com/a.jpg">');
});

it('rewrites every uploaded file of a piece of HTML, in src, srcset and links', function (): void {
    $urls = new MediaUrls('https://cms.example.com/wp-content/uploads', 'https://cdn.example.com');
    $html = '<img src="https://cms.example.com/wp-content/uploads/a.jpg" srcset="https://cms.example.com/wp-content/uploads/a-300.jpg 300w, http://cms.example.com/wp-content/uploads/a-600.jpg 600w">'
        .'<a href=\'https://cms.example.com/wp-content/uploads/file.pdf\'>f</a><a href="https://cms.example.com/about/">p</a><a href="https://cms.example.com/wp-content/uploads-old/x.jpg">o</a>';

    expect($urls->rewriteHtml($html))->toBe(
        '<img src="https://cdn.example.com/a.jpg" srcset="https://cdn.example.com/a-300.jpg 300w, https://cdn.example.com/a-600.jpg 600w">'
        .'<a href=\'https://cdn.example.com/file.pdf\'>f</a><a href="https://cms.example.com/about/">p</a><a href="https://cms.example.com/wp-content/uploads-old/x.jpg">o</a>',
    )->and($urls->rewriteHtml('<p>No file here</p>'))->toBe('<p>No file here</p>');
});

it('reads a focal point, and nothing else', function (mixed $stored, ?array $expected): void {
    expect(FocalPoint::tryFrom($stored)?->toArray())->toBe($expected);
})->with([
    [['x' => 0.25, 'y' => 0.75], ['x' => 0.25, 'y' => 0.75]],
    [['x' => '0.5', 'y' => 1], ['x' => 0.5, 'y' => 1.0]],
    [['x' => 0, 'y' => 0], ['x' => 0.0, 'y' => 0.0]],
    [['x' => 1.2, 'y' => 0.5], null],
    [['x' => -0.1, 'y' => 0.5], null],
    [['x' => 0.5], null],
    [['x' => 'left', 'y' => 'top'], null],
    ['0.5,0.5', null],
    [null, null],
    ['', null],
]);

it('tells what a front end does with a file', function (string $mime, MediaKind $kind): void {
    expect(MediaKind::fromMime($mime))->toBe($kind);
})->with([
    ['image/jpeg', MediaKind::Image],
    ['image/svg+xml', MediaKind::Image],
    ['video/mp4', MediaKind::Video],
    ['audio/mpeg', MediaKind::Audio],
    ['application/pdf', MediaKind::File],
    ['', MediaKind::File],
    ['nonsense', MediaKind::File],
]);
