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
 */
final class SettingsLoader
{
    public static function load(string $backendUrl): Settings
    {
        $config = defined('HEADLESS_CONFIG') && is_array(HEADLESS_CONFIG) ? HEADLESS_CONFIG : [];

        if (defined('HEADLESS_FRONTEND_URL') && is_string(HEADLESS_FRONTEND_URL) && ! isset($config['frontend_url'])) {
            $config['frontend_url'] = HEADLESS_FRONTEND_URL;
        }

        /** @var array<string, mixed> $config */
        return Settings::fromArray($config, $backendUrl);
    }
}
