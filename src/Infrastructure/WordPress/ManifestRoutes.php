<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * `GET /wp-json/headless/v1/site`: the manifest of the site (see SiteManifest).
 */
final readonly class ManifestRoutes implements Module
{
    public const string NAMESPACE = 'headless/v1';

    public function __construct(private SiteManifest $manifest) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/site', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            // Public on purpose: it holds what a visitor sees (menus, languages, addresses), and the front end reads it at build time.
            'permission_callback' => '__return_true',
        ]);
    }

    public function show(): \WP_REST_Response
    {
        return rest_ensure_response($this->manifest->build());
    }
}
