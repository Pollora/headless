<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Front\FrontUrls;

/**
 * Makes every link WordPress builds for a page point to the front end: the permalinks of posts, pages,
 * custom post types, terms, archives and authors (so the REST `link` field, "View post" in the editor,
 * feeds and sitemaps follow), and the links inside a post's content.
 *
 * Files (images, uploads) and the API stay on the WordPress host.
 */
final readonly class FrontLinks implements Module
{
    private const array LINK_FILTERS = ['post_link', 'page_link', 'post_type_link', 'term_link', 'post_type_archive_link', 'author_link'];

    public function __construct(
        private FrontUrls $urls,
        private ?ContentLinks $content,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        foreach (self::LINK_FILTERS as $filter) {
            $hooks->addFilter($filter, [$this, 'link'], 10, 1);
        }

        if ($this->content instanceof ContentLinks) {
            $hooks->addFilter('the_content', [$this, 'content'], 20, 1);
        }
    }

    public function link(mixed $url): mixed
    {
        return is_string($url) ? $this->urls->toFront($url) : $url;
    }

    public function content(mixed $html): mixed
    {
        return is_string($html) && $this->content instanceof ContentLinks ? $this->content->rewrite($html) : $html;
    }
}
