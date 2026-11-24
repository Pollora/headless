<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * The site the request is for: its ID on a multisite network, its locale and its URL.
 *
 * The settings, the previews and the revalidation events all carry it, so a network of sites,
 * each with its own front end and language, works from the first release.
 */
final readonly class SiteContext
{
    public function __construct(
        public int $siteId,
        public string $locale,
        public bool $multisite,
        public string $homeUrl,
    ) {}

    public static function current(): self
    {
        return new self(get_current_blog_id(), get_locale(), is_multisite(), home_url());
    }
}
