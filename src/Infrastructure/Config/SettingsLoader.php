<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Config;

/**
 * Reads the settings of a WordPress that is not Pollora: Bedrock, or a classic install.
 *
 * Define them in wp-config.php (or in a Bedrock environment file) before WordPress loads:
 *
 *     define('HEADLESS_CONFIG', ['frontend_url' => 'https://www.example.com']);
 *
 * `HEADLESS_FRONTEND_URL` is the short form for the one setting almost every site needs.
 * On a multisite network, `sites` holds the settings of one site, keyed by its ID, over the shared ones:
 *
 *     define('HEADLESS_CONFIG', ['frontend_url' => 'https://www.example.com', 'sites' => [2 => ['frontend_url' => 'https://blog.example.com']]]);
 */
final class SettingsLoader
{
    /**
     * @param  int  $siteId  the current site of a multisite network (1 on a single site): `sites` overrides the settings of one site
     */
    public static function load(string $backendUrl, int $siteId = 1): Settings
    {
        $config = defined('HEADLESS_CONFIG') && is_array(HEADLESS_CONFIG) ? HEADLESS_CONFIG : [];
        $frontendUrl = defined('HEADLESS_FRONTEND_URL') && is_string(HEADLESS_FRONTEND_URL) ? HEADLESS_FRONTEND_URL : null;

        // The short form of the signing secret, kept out of the settings array that may be printed or committed
        if (defined('HEADLESS_SECRET') && is_string(HEADLESS_SECRET) && HEADLESS_SECRET !== '') {
            $config['preview'] = [...(is_array($config['preview'] ?? null) ? $config['preview'] : []), 'secret' => HEADLESS_SECRET];
        }

        return Settings::fromArray(self::merge($config, $frontendUrl, $siteId), $backendUrl);
    }

    /**
     * The settings of one site: the shared ones, then those of `sites[$siteId]`; the short constant fills `frontend_url` when nothing else sets it.
     *
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    public static function merge(array $config, ?string $frontendUrl, int $siteId): array
    {
        $sites = $config['sites'] ?? [];
        unset($config['sites']);

        if (is_array($sites) && isset($sites[$siteId]) && is_array($sites[$siteId])) {
            $config = [...$config, ...$sites[$siteId]];
        }

        if ($frontendUrl !== null && ! isset($config['frontend_url'])) {
            $config['frontend_url'] = $frontendUrl;
        }

        /** @var array<string, mixed> $config */
        return $config;
    }
}
