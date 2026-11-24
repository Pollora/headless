<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;
use Pollora\Headless\Infrastructure\WpCli\HeadlessCommand;
use Pollora\Headless\Infrastructure\WpCli\WpCliOutput;

/*
 * Loaded by Composer's `autoload.files`: it declares `wp headless` when the
 * process is WP-CLI, and does nothing anywhere else.
 */

// @codeCoverageIgnoreStart
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    $settings = static fn (): Settings => SettingsLoader::load(home_url(), get_current_blog_id());

    WP_CLI::add_command('headless', new HeadlessCommand(
        static fn (): StatusReport => StatusReport::build(
            $settings(),
            SiteContext::current(),
            wp_get_theme()->exists(),
            defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ),
        static fn (): StubTheme => new StubTheme(get_theme_root(), dirname(__DIR__, 3).'/resources/theme'),
        static function (string $slug): void {
            switch_theme($slug);
        },
        new WpCliOutput,
    ));
}

// @codeCoverageIgnoreEnd
