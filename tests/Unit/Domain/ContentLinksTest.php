<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;

function contentLinks(string $base = ''): ContentLinks
{
    return new ContentLinks(new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com')), basePath: $base);
}

it('moves the links to pages of the site to the front end', function (): void {
    $html = '<p>Read <a class="x" href="https://cms.example.com/blog/hello/?a=1#top">this</a> and <a href=\'https://cms.example.com/about/\'>that</a>.</p>';

    expect(contentLinks()->rewrite($html))->toBe('<p>Read <a class="x" href="https://www.example.com/blog/hello/?a=1#top">this</a> and <a href=\'https://www.example.com/about/\'>that</a>.</p>');
});

it('leaves files, the API, the administration and other sites alone', function (string $href): void {
    $html = '<a href="'.$href.'">x</a>';

    expect(contentLinks()->rewrite($html))->toBe($html);
})->with([
    'upload' => ['https://cms.example.com/wp-content/uploads/2026/11/a.pdf'],
    'includes' => ['https://cms.example.com/wp-includes/js/x.js'],
    'api' => ['https://cms.example.com/wp-json/wp/v2/posts'],
    'admin' => ['https://cms.example.com/wp-admin/'],
    'other site' => ['https://other.example.org/page'],
    'relative' => ['/blog/hello'],
    'mail' => ['mailto:me@example.com'],
]);

it('leaves images and srcset on the WordPress host', function (): void {
    $html = '<img src="https://cms.example.com/blog/a.jpg" srcset="https://cms.example.com/blog/a-300.jpg 300w">';

    expect(contentLinks()->rewrite($html))->toBe($html);
});

it('reads the kept paths relative to the directory WordPress lives in', function (): void {
    $html = '<a href="https://cms.example.com/wp/wp-content/uploads/a.pdf">f</a><a href="https://cms.example.com/wp/blog">p</a>';

    expect(contentLinks('/wp')->rewrite($html))->toBe('<a href="https://cms.example.com/wp/wp-content/uploads/a.pdf">f</a><a href="https://www.example.com/wp/blog">p</a>');
});

it('returns HTML without a link untouched', function (): void {
    expect(contentLinks()->rewrite('<p>No link</p>'))->toBe('<p>No link</p>');
});
