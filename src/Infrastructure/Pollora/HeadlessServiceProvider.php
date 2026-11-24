<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Pollora;

use Illuminate\Support\ServiceProvider;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;

/**
 * Loads the package inside a Pollora (Laravel) project: Laravel discovers this provider from composer.json.
 *
 * Settings live under `pollora.headless` (see config/headless.php, published with the `headless-config` tag).
 */
final class HeadlessServiceProvider extends ServiceProvider
{
    private const string ACTION = 'Pollora\\Hook\\Domain\\Contract\\Action';

    private const string FILTER = 'Pollora\\Hook\\Domain\\Contract\\Filter';

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 3).'/config/headless.php', 'pollora.headless');
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__, 3).'/config/headless.php' => $this->app->configPath('headless.php')], 'headless-config');

        if (! $this->app->bound(self::ACTION) || ! $this->app->bound(self::FILTER)) {
            return;
        }

        /** @var array<string, mixed> $config */
        $config = $this->app->make('config')->get('pollora.headless', []);

        Bootstrap::boot(
            Settings::fromArray($config, (string) $this->app->make('config')->get('app.url', 'http://localhost')),
            new PolloraHooks($this->app->make(self::ACTION), $this->app->make(self::FILTER)),
        );
    }
}
