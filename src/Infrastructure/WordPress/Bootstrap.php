<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;

/**
 * Starts the package: builds the modules the settings ask for and attaches them.
 */
final class Bootstrap
{
    /**
     * The entry point of a classic WordPress or Bedrock: the mu-plugin loader calls it.
     *
     * The settings are read once the plugins are loaded, when home_url() can answer.
     */
    public static function fromWordPress(): void
    {
        // Not static: Brain Monkey binds the callbacks it records, and refuses a static closure
        $start = function (): void {
            self::boot(SettingsLoader::load(home_url()), new WordPressHooks);
        };

        if (did_action('plugins_loaded') > 0) {
            $start();

            return;
        }

        add_action('plugins_loaded', $start, 1);
    }

    public static function boot(Settings $settings, HookRegistrar $hooks): void
    {
        foreach (self::modules($settings) as $module) {
            $module->register($hooks);
        }
    }

    /**
     * @return list<Module>
     */
    public static function modules(Settings $settings): array
    {
        $modules = [new CleanUp($settings->cleanHead, $settings->disableXmlRpc)];

        if ($settings->gate->isActive()) {
            $modules[] = new TemplateRedirectGate($settings->gate);
        }

        return $modules;
    }
}
