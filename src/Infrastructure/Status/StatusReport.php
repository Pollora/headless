<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Status;

use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;

/**
 * What `wp headless status` says: how the site is set up, and what looks wrong.
 */
final readonly class StatusReport
{
    /**
     * @param  list<array{string, string}>  $rows  label and value
     * @param  list<string>  $warnings
     */
    private function __construct(
        public array $rows,
        public array $warnings,
    ) {}

    /**
     * @param  bool  $themeIsValid  whether the active theme exists (without one, WordPress falls into recovery mode)
     * @param  bool  $cronIsDisabled  whether DISABLE_WP_CRON is on (revalidation and scheduled posts then need a system cron)
     */
    public static function build(Settings $settings, SiteContext $site, bool $themeIsValid, bool $cronIsDisabled, ?Languages $languages = null, bool $actionSchedulerIsReady = false): self
    {
        $rows = [
            ['Site', sprintf('#%d, %s%s', $site->siteId, $site->locale, $site->multisite ? ' (multisite)' : '')],
            ['WordPress URL', $settings->backend->toString().$settings->basePath],
            ['Front-end URL', $settings->front?->toString() ?? 'not set'],
            ['Front gate', $settings->mode === FrontMode::Redirect ? sprintf('redirect (%d)', $settings->redirectStatus->value) : $settings->mode->value],
            ['Languages', $languages instanceof Languages && $languages->isActive() ? $languages->plugin().' ('.implode(', ', $languages->all()).')' : 'single language'],
            ['Preview', $settings->previewUrl ?? 'off'],
            ['Revalidation', $settings->revalidationTargets === [] ? 'off' : implode(', ', array_map(static fn (Target $target): string => $target->name, $settings->revalidationTargets)).($actionSchedulerIsReady ? ' (Action Scheduler)' : ' (WP-Cron)')],
            ['Head clean-up', self::onOff($settings->cleanHead)],
            ['XML-RPC', $settings->disableXmlRpc ? 'off' : 'on'],
            ['Feeds', $settings->disableFeeds ? 'off' : 'on'],
            ['Core sitemap', $settings->disableSitemaps ? 'off' : 'on'],
            ['Origin noindex', self::onOff($settings->noindexOrigin)],
            ['Empty theme', $themeIsValid ? 'valid theme active' : 'missing'],
            ['WP-Cron', $cronIsDisabled ? 'disabled (a system cron must run it)' : 'runs on page loads'],
        ];

        $warnings = [];

        if (! $settings->front instanceof Origin) {
            $warnings[] = 'No front-end URL: the public front of WordPress is left as it is. Set HEADLESS_FRONTEND_URL.';
        } elseif ($settings->front->sameHostAs($settings->backend)) {
            $warnings[] = 'The front end has the same host as WordPress: a redirect would loop, so the gate lets every request through.';
        }

        if ($settings->revalidationTargets !== [] && ! $actionSchedulerIsReady) {
            $warnings[] = 'Action Scheduler is not loaded: notifications of changes go through WP-Cron, which runs only when a page is visited. Install Action Scheduler (or WooCommerce) and run a system cron.';
        }

        if (! $themeIsValid) {
            $warnings[] = 'No valid theme: WordPress falls back to recovery mode for administrators. Run `wp headless theme install --activate`.';
        }

        return new self($rows, $warnings);
    }

    private static function onOff(bool $value): string
    {
        return $value ? 'on' : 'off';
    }
}
