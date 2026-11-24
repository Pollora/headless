<?php

/**
 * Plugin Name: Pollora Headless
 * Description: Prepares WordPress for headless mode (pollora/headless).
 *
 * Copy this file to wp-content/mu-plugins/ (app/mu-plugins/ on Bedrock). Composer's autoloader
 * must be loaded before: Bedrock does it, a classic install can require vendor/autoload.php
 * from wp-config.php. Settings: define('HEADLESS_CONFIG', [...]) in wp-config.php.
 */

declare(strict_types=1);
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;

if (! class_exists(Bootstrap::class)) {
    return;
}

Bootstrap::fromWordPress();
