<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * Closes what a public API leaks about the people behind it.
 *
 * - `/wp/v2/users` answers 401 to a visitor who is not logged in: core lists every author,
 *   with their login name (the slug), to anyone.
 * - The core sitemap leaves its users provider out.
 * - The theme and plugin editors are turned off in the administration.
 *
 * A request that carries a valid Application Password (or a package token) is logged in
 * by then, so a script that authenticates keeps working.
 */
final readonly class RestSecurity implements Module
{
    public function __construct(
        private bool $restrictUsers,
        private bool $disallowFileEdit,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        if ($this->restrictUsers) {
            $hooks->addFilter('rest_pre_dispatch', [$this, 'guardUsers'], 10, 3);
            $hooks->addFilter('wp_sitemaps_add_provider', [$this, 'withoutUsersProvider'], 10, 2);
        }

        if ($this->disallowFileEdit && ! defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function guardUsers(mixed $result, mixed $server, \WP_REST_Request $request): mixed
    {
        if ($result !== null || is_user_logged_in()) {
            return $result;
        }

        if (preg_match('#\A/wp/v2/users(/|\z)#', $request->get_route()) !== 1) {
            return $result;
        }

        return new \WP_Error('rest_user_cannot_view', 'Sorry, you must be logged in to see users.', ['status' => 401]);
    }

    public function withoutUsersProvider(mixed $provider, string $name): mixed
    {
        return $name === 'users' ? false : $provider;
    }
}
