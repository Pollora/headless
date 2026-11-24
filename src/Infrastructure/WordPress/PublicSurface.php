<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * What WordPress still publishes on its own host once the front end lives elsewhere: feeds, the core
 * sitemap (its URLs are the WordPress ones) and the invitation to index the origin.
 *
 * The front gate already answers the public pages; this module covers the requests that reach WordPress
 * through another door (the gate is off, the path is allow-listed) and the answers that leak the host.
 */
final readonly class PublicSurface implements Module
{
    private const array FEEDS = ['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'];

    public function __construct(
        private bool $disableFeeds,
        private bool $disableSitemaps,
        private bool $noindexOrigin,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        if ($this->disableFeeds) {
            foreach (self::FEEDS as $feed) {
                $hooks->addAction($feed, [$this, 'refuseFeed'], 1);
            }
        }

        if ($this->disableSitemaps) {
            $hooks->addFilter('wp_sitemaps_enabled', '__return_false');
        }

        if ($this->noindexOrigin) {
            $hooks->addFilter('wp_headers', [$this, 'withNoindexHeader']);
            $hooks->addFilter('robots_txt', [$this, 'robotsTxt']);
            $hooks->addFilter('wp_robots', [$this, 'robots']);
        }
    }

    /** A feed of the WordPress host is a 404: the front end publishes its own. */
    public function refuseFeed(): void
    {
        wp_die('', '', ['response' => 404]);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function withNoindexHeader(array $headers): array
    {
        $headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $headers;
    }

    public function robotsTxt(): string
    {
        return "User-agent: *\nDisallow: /\n";
    }

    /**
     * @param  array<string, bool|string>  $robots
     * @return array<string, bool|string>
     */
    public function robots(array $robots): array
    {
        return [...$robots, 'noindex' => true, 'nofollow' => true];
    }
}
