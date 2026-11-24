<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

use Closure;
use Pollora\Headless\Infrastructure\Exceptions\ThemeInstallException;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;

/**
 * Inspect and prepare a headless WordPress.
 */
final readonly class HeadlessCommand
{
    /**
     * @param  Closure(): StatusReport  $report  built when a command runs: wp-config.php has been read by then
     * @param  Closure(): StubTheme  $theme
     * @param  Closure(string): void  $activate  switches the active theme to the given slug
     */
    public function __construct(
        private Closure $report,
        private Closure $theme,
        private Closure $activate,
        private Output $output,
    ) {}

    /**
     * Show how the site is set up for headless mode, and what looks wrong.
     *
     * ## EXAMPLES
     *
     *     wp headless status
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function status(array $args = [], array $assoc = []): void
    {
        $report = ($this->report)();

        foreach ($report->rows as [$label, $value]) {
            $this->output->line(sprintf('%-15s %s', $label.':', $value));
        }

        foreach ($report->warnings as $warning) {
            $this->output->warning($warning);
        }

        if ($report->warnings === []) {
            $this->output->success('Nothing to report.');
        }
    }

    /**
     * Install the empty theme WordPress needs when the front end lives elsewhere.
     *
     * ## OPTIONS
     *
     * [--activate]
     * : Make it the active theme once installed.
     *
     * ## EXAMPLES
     *
     *     wp headless theme install --activate
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function theme(array $args = [], array $assoc = []): void
    {
        if (($args[0] ?? '') !== 'install') {
            $this->output->error('Usage: wp headless theme install [--activate]');

            return;
        }

        try {
            $installed = ($this->theme)()->install();
        } catch (ThemeInstallException $themeInstallException) {
            $this->output->error($themeInstallException->getMessage());

            return;
        }

        $this->output->success($installed ? 'Installed the empty theme.' : 'The empty theme is already installed.');

        if (($assoc['activate'] ?? false) === true) {
            ($this->activate)(StubTheme::SLUG);
            $this->output->success('Activated the empty theme.');
        }
    }
}
