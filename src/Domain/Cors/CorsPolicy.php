<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Cors;

use Pollora\Headless\Domain\Exceptions\InvalidOriginException;
use Pollora\Headless\Domain\Front\Origin;

/**
 * Which origins may call the API from a browser.
 *
 * WordPress's own answer reflects any origin and allows credentials, so any website can read what a
 * logged-in visitor can. This policy answers only for the origins it lists, never with `*`, and always
 * says `Vary: Origin` so that a cache does not serve one origin's answer to another.
 *
 * An entry is an origin (`https://www.example.com`) or a subdomain pattern (`https://*.example.com`).
 */
final readonly class CorsPolicy
{
    /** @var list<string> */
    private array $exact;

    /** @var list<array{string, string}> scheme and host suffix of each `*.` pattern */
    private array $patterns;

    /**
     * @param  list<string>  $origins
     * @param  list<string>  $methods
     * @param  list<string>  $headers  request headers a preflight may ask for
     * @param  list<string>  $exposeHeaders  response headers a script may read
     */
    public function __construct(
        array $origins,
        private bool $credentials = false,
        private array $methods = ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'],
        private array $headers = ['Authorization', 'Content-Type', 'Accept', 'X-WP-Nonce', 'X-HTTP-Method-Override', 'X-GraphQL-Preview'],
        private int $maxAge = 600,
        private array $exposeHeaders = ['X-WP-Total', 'X-WP-TotalPages', 'Link'],
    ) {
        $exact = [];
        $patterns = [];

        foreach ($origins as $origin) {
            $origin = trim($origin);

            if (preg_match('#\A(https?)://\*\.([^/:]+(?::\d+)?)\z#i', $origin, $match) === 1) {
                $patterns[] = [strtolower($match[1]), strtolower($match[2])];

                continue;
            }

            $exact[] = Origin::fromUrl($origin)->toString();
        }

        $this->exact = $exact;
        $this->patterns = $patterns;
    }

    /** Whether at least one origin is allowed: with none, the policy has nothing to say. */
    public function hasOrigins(): bool
    {
        return $this->exact !== [] || $this->patterns !== [];
    }

    public function decide(CorsRequest $request): CorsDecision
    {
        $vary = ['Vary' => 'Origin'];

        if ($request->origin === null || ! $this->allows($request->origin)) {
            return new CorsDecision($vary, false);
        }

        $headers = $vary + [
            'Access-Control-Allow-Origin' => $request->origin,
            'Access-Control-Expose-Headers' => implode(', ', $this->exposeHeaders),
        ];

        if ($this->credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($request->isPreflight()) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $this->methods);
            $headers['Access-Control-Allow-Headers'] = $this->allowedHeaders($request->requestHeaders);
            $headers['Access-Control-Max-Age'] = (string) $this->maxAge;
        }

        return new CorsDecision($headers, true);
    }

    /** Whether the origin of a request is one of the listed origins. The "null" origin never is. */
    public function allows(string $origin): bool
    {
        try {
            $candidate = Origin::fromUrl($origin);
        } catch (InvalidOriginException) {
            return false;
        }

        // A path or a query on an Origin header is not an origin
        if (strcasecmp($candidate->toString(), rtrim($origin, '/')) !== 0) {
            return false;
        }

        if (in_array($candidate->toString(), $this->exact, true)) {
            return true;
        }

        foreach ($this->patterns as [$scheme, $suffix]) {
            $host = $candidate->host.($candidate->port === null ? '' : ':'.$candidate->port);

            if ($candidate->scheme === $scheme && str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** The requested headers when they are all allowed, the allowed ones otherwise. */
    private function allowedHeaders(?string $requested): string
    {
        $allowed = array_map(strtolower(...), $this->headers);
        $asked = array_values(array_filter(array_map(trim(...), explode(',', (string) $requested)), static fn (string $name): bool => $name !== ''));

        if ($asked !== [] && array_diff(array_map(strtolower(...), $asked), $allowed) === []) {
            return implode(', ', $asked);
        }

        return implode(', ', $this->headers);
    }
}
