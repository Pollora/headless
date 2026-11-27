<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Redirect\RedirectRules;
use Pollora\Headless\Domain\Scalar;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\Seo\SeoProviders;
use Pollora\Headless\Infrastructure\Status\DoctorFacts;

/**
 * Asks the running site what the doctor needs to know: it tries the REST API as a visitor would, through WordPress itself.
 */
final readonly class DoctorProbe
{
    public function __construct(private RedirectCatalog $redirects) {}

    public function facts(): DoctorFacts
    {
        $graphql = function_exists('get_graphql_setting');

        return new DoctorFacts(
            self::anonymousStatus('/wp/v2/users'),
            self::anonymousStatus('/wp/v2/posts'),
            Scalar::string(get_option('permalink_structure', '')),
            defined('WP_DEBUG_DISPLAY') ? (bool) WP_DEBUG_DISPLAY : (defined('WP_DEBUG') && WP_DEBUG),
            wp_get_theme()->exists(),
            defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            ActionSchedulerScheduler::actionSchedulerIsReady(),
            $graphql,
            $graphql ? self::introspectionIsOpen() : null,
            self::rules(),
            ($name = SeoProviders::detect()->name()) === 'none' ? null : $name,
        );
    }

    /**
     * The status WordPress gives a visitor who is not logged in.
     */
    private static function anonymousStatus(string $route): ?int
    {
        $user = get_current_user_id();
        wp_set_current_user(0);

        try {
            return rest_do_request(new \WP_REST_Request('GET', $route))->get_status();
        } catch (\Throwable) {
            return null;
        } finally {
            wp_set_current_user($user);
        }
    }

    private static function introspectionIsOpen(): bool
    {
        return get_graphql_setting('public_introspection_enabled', 'off') !== 'off';
    }

    private function rules(): RedirectRules
    {
        return $this->redirects->rules();
    }
}
