<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Cache;

/**
 * When a REST answer may be kept by a browser, a CDN or the front end, and for how long.
 *
 * WordPress sends no Cache-Control at all to a visitor, so a CDN keeps nothing. Only an anonymous
 * GET or HEAD that succeeded, on a listed namespace, is public: anything that depends on who asks
 * (a logged-in user, an Authorization header, the "me" routes) is never marked.
 */
final readonly class RestCachePolicy
{
    /**
     * @param  list<string>  $namespaces  route prefixes whose answers are public content, such as /wp/v2
     * @param  list<string>  $neverRoutes  route prefixes that are never public, even inside a namespace
     */
    public function __construct(
        private int $maxAge = 60,
        private int $sharedMaxAge = 300,
        private int $staleWhileRevalidate = 60,
        private array $namespaces = ['/wp/v2', '/headless/v1/site', '/headless/v1/redirects', '/headless/v1/media'],
        private array $neverRoutes = ['/wp/v2/users/me', '/wp/v2/settings', '/wp/v2/block-renderer', '/wp/v2/search'],
    ) {}

    /**
     * The Cache-Control value for this answer, or null when it must not be marked.
     */
    public function cacheControl(string $method, int $status, bool $authenticated, bool $hasAuthorization, string $route): ?string
    {
        $method = strtoupper($method);

        if (($method !== 'GET' && $method !== 'HEAD') || $status !== 200 || $authenticated || $hasAuthorization) {
            return null;
        }

        if (! self::under($route, $this->namespaces) || self::under($route, $this->neverRoutes)) {
            return null;
        }

        return sprintf('public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d', $this->maxAge, $this->sharedMaxAge, $this->staleWhileRevalidate);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function under(string $route, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
