<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Media\MediaUrls;

/**
 * Serves the uploaded files from a CDN: the address of a file, the sources of its `srcset` and the files named in
 * a post's HTML are moved to the CDN. The administration keeps the WordPress addresses, so that an editor sees
 * a file they have just uploaded before the CDN has fetched it.
 */
final readonly class MediaCdn implements Module
{
    public function __construct(private MediaUrls $urls) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('wp_get_attachment_url', [$this, 'url']);
        $hooks->addFilter('wp_calculate_image_srcset', [$this, 'srcset']);
        $hooks->addFilter('the_content', [$this, 'html'], 20);
    }

    public function url(mixed $url): mixed
    {
        return is_string($url) && ! is_admin() ? $this->urls->toCdn($url) : $url;
    }

    public function srcset(mixed $sources): mixed
    {
        if (! is_array($sources) || is_admin()) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            if (is_array($source) && is_string($source['url'] ?? null)) {
                $sources[$width]['url'] = $this->urls->toCdn($source['url']);
            }
        }

        return $sources;
    }

    public function html(mixed $html): mixed
    {
        return is_string($html) && ! is_admin() ? $this->urls->rewriteHtml($html) : $html;
    }
}
