<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Pollora;

use Illuminate\Support\ServiceProvider;
use Pollora\Headless\Domain\Scalar;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Exceptions\HookServiceException;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;
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

        $repository = $this->app->make('config');

        if (! is_object($repository) || ! method_exists($repository, 'get')) {
            throw InvalidSettingsException::unreadable('config');
        }

        $config = $repository->get('pollora.headless', []);

        // Booting with no settings would leave the public front open: a broken configuration must be seen
        if (! is_array($config)) {
            throw InvalidSettingsException::unreadable('pollora.headless');
        }

        $action = $this->app->make(self::ACTION);
        $filter = $this->app->make(self::FILTER);

        if (! is_object($action) || ! is_object($filter)) {
            throw HookServiceException::notAnObject();
        }

        Bootstrap::boot(
            Settings::fromArray($config, Scalar::string($repository->get('app.url', 'http://localhost'))),
            new PolloraHooks($action, $filter),
        );
    }
}
