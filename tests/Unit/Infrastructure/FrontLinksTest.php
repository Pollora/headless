<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\WordPress\FrontLinks;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function linksModule(bool $content = true): FrontLinks
{
    $mapper = new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'));

    return new FrontLinks($mapper, $content ? new ContentLinks($mapper) : null);
}

it('hooks every permalink filter and the content', function (): void {
    foreach (['post_link', 'page_link', 'post_type_link', 'term_link', 'post_type_archive_link', 'author_link', 'the_content'] as $filter) {
        Filters\expectAdded($filter)->once();
    }

    linksModule()->register(new WordPressHooks);
});

it('does not hook the content when its links are left alone', function (): void {
    Filters\expectAdded('the_content')->never();

    linksModule(false)->register(new WordPressHooks);
});

it('moves a permalink and leaves a value that is not a string', function (): void {
    expect(linksModule()->link('https://cms.example.com/blog/hello/'))->toBe('https://www.example.com/blog/hello/')
        ->and(linksModule()->link(false))->toBeFalse();
});

it('moves the links of the content', function (): void {
    expect(linksModule()->content('<a href="https://cms.example.com/about/">a</a>'))->toBe('<a href="https://www.example.com/about/">a</a>')
        ->and(linksModule()->content(['not html']))->toBe(['not html'])
        ->and(linksModule(false)->content('<a href="https://cms.example.com/about/">a</a>'))->toBe('<a href="https://cms.example.com/about/">a</a>');
});
