<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Config;

use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Front\AllowList;
use Pollora\Headless\Domain\Front\FrontGatePolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\RedirectStatus;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;

/**
 * The settings of the package, checked once and read as objects.
 *
 * Without a front-end URL the package changes nothing visible: a site that is not
 * told where its front end lives must not lock its visitors out.
 */
final readonly class Settings
{
    /**
     * @param  array<string, Origin>  $languageFronts  the front-end origin of the languages that have their own
     */
    private function __construct(
        public FrontGatePolicy $gate,
        public ?UrlMapper $urls,
        public bool $cleanHead,
        public bool $disableXmlRpc,
        public FrontMode $mode,
        public ?Origin $front,
        public Origin $backend,
        public string $basePath,
        public RedirectStatus $redirectStatus,
        public bool $disableFeeds,
        public bool $disableSitemaps,
        public bool $noindexOrigin,
        public ?CorsPolicy $cors,
        public bool $restrictUsers,
        public bool $disallowFileEdit,
        public ?RestCachePolicy $restCache,
        public ?int $perPageMax,
        public bool $rewriteLinks,
        public bool $rewriteContentLinks,
        public array $languageFronts,
        public bool $apiTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `headless` settings (see config/headless.php for every key)
     * @param  string  $backendUrl  the URL of the WordPress site, normally home_url()
     */
    public static function fromArray(array $config, string $backendUrl): self
    {
        $backend = Origin::fromUrl($backendUrl);
        $frontUrl = self::string($config, 'frontend_url');
        $front = $frontUrl === '' ? null : Origin::fromUrl($frontUrl);

        $mode = self::mode(self::string($config, 'mode'), $front instanceof Origin);
        $redirectStatus = self::redirectStatus(self::string($config, 'redirect_status', '302'));

        $gate = new FrontGatePolicy(
            mode: $mode,
            allowList: AllowList::defaults()->with(...self::strings($config, 'allow')),
            backend: $backend,
            front: $front,
            redirectStatus: $redirectStatus,
            preserveQuery: self::bool($config, 'preserve_query', true),
            blockedStatus: self::int($config, 'blocked_status', 404),
        );

        return new self(
            gate: $gate,
            urls: $front instanceof Origin ? new UrlMapper($backend, $front) : null,
            cleanHead: self::bool($config, 'clean_head', true),
            disableXmlRpc: self::bool($config, 'disable_xmlrpc', true),
            mode: $mode,
            front: $front,
            backend: $backend,
            basePath: rtrim((string) parse_url($backendUrl, PHP_URL_PATH), '/'),
            redirectStatus: $redirectStatus,
            disableFeeds: self::bool($config, 'disable_feeds', true),
            disableSitemaps: self::bool($config, 'disable_sitemaps', true),
            noindexOrigin: self::bool($config, 'noindex_origin', true),
            cors: self::cors($config['cors'] ?? [], $front),
            restrictUsers: self::bool($config, 'restrict_users', true),
            disallowFileEdit: self::bool($config, 'disallow_file_edit', true),
            restCache: $front instanceof Origin ? self::restCache($config['rest_cache'] ?? []) : null,
            perPageMax: self::perPageMax($config),
            rewriteLinks: self::bool($config, 'rewrite_links', true),
            rewriteContentLinks: self::bool($config, 'rewrite_content_links', true),
            languageFronts: self::languageFronts($config['frontend_urls'] ?? []),
            apiTokens: self::bool(is_array($config['tokens'] ?? null) ? $config['tokens'] : [], 'enabled', true),
        );
    }

    /**
     * The CORS policy, or null when no origin is allowed (the front-end URL counts): then WordPress's own answer stays.
     */
    private static function cors(mixed $config, ?Origin $front): ?CorsPolicy
    {
        $config = is_array($config) ? $config : [];

        if (! self::bool($config, 'enabled', true)) {
            return null;
        }

        $origins = self::strings($config, 'origins');

        if ($front instanceof Origin) {
            $origins[] = $front->toString();
        }

        $policy = new CorsPolicy(
            array_values(array_unique($origins)),
            self::bool($config, 'credentials', false),
            maxAge: self::int($config, 'max_age', 600),
        );

        return $policy->hasOrigins() ? $policy : null;
    }

    private static function restCache(mixed $config): ?RestCachePolicy
    {
        $config = is_array($config) ? $config : [];

        if (! self::bool($config, 'enabled', true)) {
            return null;
        }

        return new RestCachePolicy(
            self::int($config, 'max_age', 60),
            self::int($config, 's_maxage', 300),
            self::int($config, 'stale_while_revalidate', 60),
        );
    }

    /**
     * The ceiling for `per_page`; null when it would not lower WordPress's own (100).
     *
     * @param  array<string, mixed>  $config
     */
    private static function perPageMax(array $config): ?int
    {
        $max = self::int($config, 'per_page_max', 100);

        return $max >= 1 && $max < 100 ? $max : null;
    }

    /**
     * The front-end origin of each language, when a language has its own.
     *
     * @return array<string, Origin>
     */
    private static function languageFronts(mixed $config): array
    {
        $fronts = [];

        foreach (is_array($config) ? $config : [] as $code => $url) {
            if (is_string($code) && is_string($url) && trim($url) !== '') {
                $fronts[$code] = Origin::fromUrl($url);
            }
        }

        return $fronts;
    }

    private static function mode(string $value, bool $hasFront): FrontMode
    {
        if ($value === '') {
            return $hasFront ? FrontMode::Redirect : FrontMode::Off;
        }

        return FrontMode::tryFrom($value)
            ?? throw InvalidSettingsException::forValue('mode', $value, array_map(static fn (FrontMode $mode): string => $mode->value, FrontMode::cases()));
    }

    private static function redirectStatus(string $value): RedirectStatus
    {
        return RedirectStatus::tryFrom((int) $value)
            ?? throw InvalidSettingsException::forValue('redirect_status', $value, array_map(static fn (RedirectStatus $status): string => (string) $status->value, RedirectStatus::cases()));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function strings(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value), static fn (string $item): bool => $item !== ''));
    }
}
