<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

use Pollora\Headless\Domain\Front\UrlPath;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoMapper;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * Rank Math, through its `/rankmath/v1/getHead?url=` route, which answers the head of a page as HTML.
 *
 * The route exists only when "Headless Support" is on in Rank Math's general settings, and it accepts only an
 * address of the WordPress site: the permalink is asked for with the WordPress host, whatever the front end is.
 */
final class RankMathProvider implements SeoProvider
{
    public function name(): string
    {
        return 'rankmath';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function forPost(int $postId): ?Seo
    {
        $permalink = get_permalink($postId);

        return is_string($permalink) ? $this->read($permalink) : null;
    }

    public function forTerm(int $termId, string $taxonomy): ?Seo
    {
        $link = get_term_link($termId, $taxonomy);

        return is_string($link) ? $this->read($link) : null;
    }

    private function read(string $address): ?Seo
    {
        $head = InternalRest::get('/rankmath/v1/getHead', ['url' => home_url(UrlPath::of($address))])['head'] ?? null;

        return is_string($head) && $head !== '' ? SeoMapper::headHtml($head) : null;
    }
}
