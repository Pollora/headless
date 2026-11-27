<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Plugin\PluginFilter;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;

/**
 * Loads only the plugins the site lists on REST requests (the `rest_plugins` setting). Off by default; advanced.
 *
 * It filters `option_active_plugins`, which WordPress reads once the mu-plugins have loaded and before it loads the
 * plugins: so it must be registered from the mu-plugin itself, at once, not on `plugins_loaded`. Plugins activated
 * for the whole network of a multisite are not touched. A plugin left out loses its routes, types, fields and
 * authentication on REST requests, so list every plugin that adds one (ACF, a form plugin, an SEO plugin…).
 */
final readonly class RestPlugins implements Module
{
    public function __construct(
        private PluginFilter $filter,
        private bool $isRestRequest,
    ) {}

    /**
     * The module the settings ask for, or null when the setting is off.
     *
     * @param  array<mixed>  $query  `$_GET`
     */
    public static function fromSettings(string $requestUri, array $query): ?self
    {
        $config = SettingsLoader::config(function_exists('get_current_blog_id') ? get_current_blog_id() : 1)['rest_plugins'] ?? null;

        if (! is_array($config) || ($config['enabled'] ?? false) !== true) {
            return null;
        }

        $keep = array_values(array_filter(is_array($config['keep'] ?? null) ? $config['keep'] : [], is_string(...)));

        return new self(new PluginFilter($keep), PluginFilter::isRestRequest($requestUri, isset($query['rest_route'])));
    }

    public function register(HookRegistrar $hooks): void
    {
        if (! $this->isRestRequest) {
            return;
        }

        $hooks->addFilter('option_active_plugins', [$this, 'plugins'], 1);
    }

    /**
     * @return list<string>|mixed
     */
    public function plugins(mixed $active): mixed
    {
        return is_array($active) ? $this->filter->filter($active) : $active;
    }
}
