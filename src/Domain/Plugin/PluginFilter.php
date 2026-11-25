<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Plugin;

/**
 * Which active plugins a REST request loads, when the site keeps only the ones it needs there.
 *
 * Most plugins do nothing for the REST API but cost time on every request (their hooks, their options, their
 * scripts). Loading only the ones that register routes, types, fields or authentication makes the API faster,
 * but a plugin left out loses everything it adds: that is why the list is the site's own and the filter is off by default.
 */
final readonly class PluginFilter
{
    /**
     * @param  list<string>  $keep  plugins to load on REST requests: a file (`acf/acf.php`) or a folder (`acf`)
     */
    public function __construct(private array $keep) {}

    /**
     * @param  array<mixed>  $active  the plugin files WordPress would load (`folder/file.php`)
     * @return list<string>
     */
    public function filter(array $active): array
    {
        $kept = [];

        foreach ($active as $plugin) {
            if (is_string($plugin) && $this->keeps($plugin)) {
                $kept[] = $plugin;
            }
        }

        return $kept;
    }

    public function keeps(string $plugin): bool
    {
        $folder = str_contains($plugin, '/') ? explode('/', $plugin, 2)[0] : $plugin;

        return in_array($plugin, $this->keep, true) || in_array($folder, $this->keep, true);
    }

    /**
     * Whether a request is one for the REST API, from its address alone, because it must be known before the plugins load.
     *
     * @param  string  $requestUri  `$_SERVER['REQUEST_URI']`
     * @param  bool  $hasRestRoute  whether the query string has `rest_route` (plain permalinks)
     */
    public static function isRestRequest(string $requestUri, bool $hasRestRoute = false): bool
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);

        return $hasRestRoute || preg_match('#(?:^|/)wp-json(?:/|$)#', $path) === 1;
    }
}
