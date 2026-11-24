<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * Turns a URL of the WordPress site into the URL of the same page on the front end.
 *
 * Only a URL on the WordPress host is rewritten: a relative URL, another site and
 * a mailto: link are returned as they are. The path, the query and the fragment
 * are kept. Every URL the package hands out goes through here, so the rule lives
 * in one place.
 */
final readonly class UrlMapper implements FrontUrls
{
    public function __construct(
        private Origin $backend,
        private Origin $front,
    ) {}

    public function toFront(string $url, ?string $language = null): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return $url;
        }

        $origin = Origin::fromUrl($scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''));

        if (! $origin->sameHostAs($this->backend)) {
            return $url;
        }

        return $this->front->toString()
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
