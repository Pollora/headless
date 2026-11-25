<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;

/**
 * The redirects of the site for a front end, under `/wp-json/headless/v1/redirects`:
 *
 * - `GET /redirects`: every rule (`from`, `to`, `status`, `regex`, `source`), for a front end that loads them at build or start time.
 * - `GET /redirects/resolve?path=/old/`: where one path goes, `{"redirect": null}` when nowhere (a 200, so a CDN keeps it).
 */
final readonly class RedirectRoutes implements Module
{
    public const string NAMESPACE = 'headless/v1';

    public function __construct(private RedirectCatalog $catalog) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
        // The list of old slugs follows the posts
        $hooks->addAction('post_updated', [OldSlugRedirects::class, 'forget']);
        $hooks->addAction('deleted_post', [OldSlugRedirects::class, 'forget']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/redirects', [
            'methods' => 'GET',
            'callback' => [$this, 'all'],
            // Public on purpose: a redirect is an address a visitor already knows, and the front end reads the list to serve it.
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/redirects/resolve', [
            'methods' => 'GET',
            'callback' => [$this, 'resolve'],
            'permission_callback' => '__return_true',
            'args' => ['path' => ['type' => 'string', 'required' => true, 'pattern' => '\A/\S{0,511}\z']],
        ]);
    }

    public function all(): \WP_REST_Response
    {
        $rules = $this->catalog->rules()->all();

        return rest_ensure_response([
            'count' => count($rules),
            'redirects' => array_map(static fn (Redirect $rule): array => $rule->toArray(), array_slice($rules, 0, OptionRedirectStore::LIMIT)),
            'truncated' => count($rules) > OptionRedirectStore::LIMIT,
        ]);
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function resolve(\WP_REST_Request $request): \WP_REST_Response
    {
        $match = $this->catalog->rules()->match((string) $request->get_param('path'));

        return rest_ensure_response([
            'redirect' => $match === null ? null : ['from' => $match['rule']->from, 'to' => $match['to'], 'status' => $match['rule']->status, 'source' => $match['rule']->source],
        ]);
    }
}
