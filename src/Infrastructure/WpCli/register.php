<?php

declare(strict_types=1);

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;
use Pollora\Headless\Infrastructure\Language\LanguagesFactory;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\Revalidation\RevalidatorFactory;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;
use Pollora\Headless\Infrastructure\WordPress\UserMetaTokenStore;
use Pollora\Headless\Infrastructure\WpCli\HeadlessCommand;
use Pollora\Headless\Infrastructure\WpCli\RedirectCommand;
use Pollora\Headless\Infrastructure\WpCli\RevalidateCommand;
use Pollora\Headless\Infrastructure\WpCli\TokenCommand;
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
            LanguagesFactory::detect(),
            ActionSchedulerScheduler::actionSchedulerIsReady(),
        ),
        static fn (): StubTheme => new StubTheme(get_theme_root(), dirname(__DIR__, 3).'/resources/theme'),
        static function (string $slug): void {
            switch_theme($slug);
        },
        new WpCliOutput,
    ));
    WP_CLI::add_command('headless revalidate ping', new RevalidateCommand(
        static fn (): Revalidator => RevalidatorFactory::fromSettings($settings()),
        new WpCliOutput,
    ));

    WP_CLI::add_command('headless redirect', new RedirectCommand(
        new OptionRedirectStore,
        static function () use ($settings): RedirectCatalog {
            $config = $settings();
            $urls = $config->urls ?? new UrlMapper($config->backend, $config->backend);

            return new RedirectCatalog([new OptionRedirectStore, new OldSlugRedirects($urls)]);
        },
        new WpCliOutput,
    ));

    WP_CLI::add_command('headless token', new TokenCommand(
        new TokenService(new UserMetaTokenStore),
        static function (string $who): ?int {
            $user = ctype_digit($who) ? get_user_by('id', (int) $who) : (is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who));

            return $user instanceof WP_User ? $user->ID : null;
        },
        static fn (): int => time(),
        new WpCliOutput,
    ));
}

// @codeCoverageIgnoreEnd
