<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Closure;
use Pollora\Headless\Domain\Cache\Etag;
use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Domain\Scalar;

/**
 * Makes the REST API cheap to ask again.
 *
 * - Cache-Control on the public answers (see RestCachePolicy), so a CDN keeps them.
 * - An ETag on those answers, and a 304 with no body when the client already holds the version.
 * - A ceiling on `per_page`: one request cannot ask WordPress to build a hundred embedded posts.
 */
final class RestPerformance implements Module
{
    private readonly Closure $sendHeader;

    private readonly Closure $sendStatus;

    /**
     * @param  int|null  $perPageMax  the largest `per_page` a request may ask for; null leaves WordPress's own ceiling (100)
     * @param  (callable(string): void)|null  $sendHeader  sends one header line; replaced in tests
     * @param  (callable(int): void)|null  $sendStatus  sends the status code; replaced in tests
     */
    public function __construct(
        private readonly ?RestCachePolicy $cache,
        private readonly ?int $perPageMax,
        ?callable $sendHeader = null,
        ?callable $sendStatus = null,
    ) {
        $this->sendHeader = $sendHeader !== null ? Closure::fromCallable($sendHeader) : static function (string $line): void {
            header($line);
        };
        $this->sendStatus = $sendStatus !== null ? Closure::fromCallable($sendStatus) : static function (int $status): void {
            status_header($status);
        };
    }

    public function register(HookRegistrar $hooks): void
    {
        if ($this->cache instanceof RestCachePolicy) {
            $hooks->addFilter('rest_post_dispatch', [$this, 'markCacheable'], 20, 3);
            $hooks->addFilter('rest_pre_serve_request', [$this, 'validate'], 5, 3);
        }

        if ($this->perPageMax !== null) {
            $hooks->addFilter('rest_pre_dispatch', [$this, 'capPerPage'], 5, 3);
        }
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function markCacheable(mixed $response, mixed $server, \WP_REST_Request $request): mixed
    {
        if (! $response instanceof \WP_HTTP_Response || ! $this->cache instanceof RestCachePolicy) {
            return $response;
        }

        $value = $this->cache->cacheControl(
            $request->get_method(),
            $response->get_status(),
            is_user_logged_in(),
            $request->get_header('authorization') !== null,
            $request->get_route(),
        );

        if ($value !== null) {
            $response->header('Cache-Control', $value);
        }

        return $response;
    }

    /**
     * Adds the ETag of a public answer and ends the request with a 304 when the client holds it already.
     *
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function validate(bool $served, mixed $result, \WP_REST_Request $request): bool
    {
        if ($served || ! $result instanceof \WP_HTTP_Response || ! str_starts_with(Scalar::string($result->get_headers()['Cache-Control'] ?? ''), 'public')) {
            return $served;
        }

        $body = wp_json_encode($result->get_data());
        $etag = Etag::forBody(is_string($body) ? $body : '');

        ($this->sendHeader)('ETag: '.$etag);

        if (! Etag::matches($request->get_header('if_none_match'), $etag)) {
            return $served;
        }

        ($this->sendStatus)(304);

        return true;
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function capPerPage(mixed $result, mixed $server, \WP_REST_Request $request): mixed
    {
        $asked = $request->get_param('per_page');

        if ($this->perPageMax !== null && is_numeric($asked) && (int) $asked > $this->perPageMax) {
            $request->set_param('per_page', $this->perPageMax);
        }

        return $result;
    }
}
