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
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;
use Pollora\Headless\Infrastructure\WordPress\ChangeWatcher;

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
     * @param  list<Target>  $revalidationTargets  where changes are announced
     * @param  list<string>  $revalidationOptions  the options whose change is announced
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
        public ?string $previewUrl,
        public ?string $previewSecret,
        public int $previewLinkTtl,
        public int $previewSessionTtl,
        public array $revalidationTargets,
        public int $revalidationDebounce,
        public int $revalidationAttempts,
        public array $revalidationOptions,
        public bool $manifest,
        public bool $redirects,
        public bool $oldSlugRedirects,
        public bool $mediaRoutes,
        public ?string $mediaCdn,
        public bool $blocks,
        public bool $seo,
        public ?GraphQlPolicy $graphql,
        public bool $search,
        public bool $forms,
        public bool $restPluginFilter,
    ) {}

    /**
     * @param  array<mixed>  $config  the `headless` settings (see config/headless.php for every key)
     * @param  string  $backendUrl  the URL of the WordPress site, normally home_url()
     */
    public static function fromArray(array $config, string $backendUrl): self
    {
        $backend = Origin::fromUrl($backendUrl);
        $preview = is_array($config['preview'] ?? null) ? $config['preview'] : [];
        $revalidation = is_array($config['revalidation'] ?? null) ? $config['revalidation'] : [];
        $media = is_array($config['media'] ?? null) ? $config['media'] : [];
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
            previewUrl: self::previewUrl($preview, $front),
            previewSecret: self::secret($preview),
            previewLinkTtl: max(30, self::int($preview, 'link_ttl', 300)),
            previewSessionTtl: max(60, self::int($preview, 'session_ttl', 1800)),
            revalidationTargets: self::targets($revalidation),
            revalidationDebounce: max(0, self::int($revalidation, 'debounce', 3)),
            revalidationAttempts: min(5, max(1, self::int($revalidation, 'max_attempts', 5))),
            revalidationOptions: array_values(array_unique([...ChangeWatcher::defaultOptions(), ...self::strings($revalidation, 'options')])),
            manifest: $front instanceof Origin && self::bool(is_array($config['manifest'] ?? null) ? $config['manifest'] : [], 'enabled', true),
            redirects: $front instanceof Origin && self::bool(is_array($config['redirects'] ?? null) ? $config['redirects'] : [], 'enabled', true),
            oldSlugRedirects: self::bool(is_array($config['redirects'] ?? null) ? $config['redirects'] : [], 'old_slugs', true),
            mediaRoutes: $front instanceof Origin && self::bool($media, 'enabled', true),
            mediaCdn: self::mediaCdn($media),
            blocks: $front instanceof Origin && self::bool(is_array($config['blocks'] ?? null) ? $config['blocks'] : [], 'enabled', true),
            seo: $front instanceof Origin && self::bool(is_array($config['seo'] ?? null) ? $config['seo'] : [], 'enabled', true),
            graphql: self::graphql($config['graphql'] ?? null),
            search: $front instanceof Origin && self::bool(is_array($config['search'] ?? null) ? $config['search'] : [], 'enabled', true),
            forms: self::bool(is_array($config['forms'] ?? null) ? $config['forms'] : [], 'enabled', true),
            restPluginFilter: self::bool(is_array($config['rest_plugins'] ?? null) ? $config['rest_plugins'] : [], 'enabled', false),
        );
    }

    /**
     * The signing secret of the preview, null when it is left to the salts of the site.
     *
     * @param  array<mixed>  $preview
     *
     * @throws InvalidSettingsException when it is too short to resist guessing
     */
    private static function secret(array $preview): ?string
    {
        $secret = self::string($preview, 'secret');

        if ($secret === '') {
            return null;
        }

        if (strlen($secret) < 32) {
            throw InvalidSettingsException::tooShort('preview.secret', 32);
        }

        return $secret;
    }

    /**
     * The WPGraphQL settings to impose, or null when the module is off. A key set to null leaves that setting to WPGraphQL.
     */
    private static function graphql(mixed $config): ?GraphQlPolicy
    {
        $config = is_array($config) ? $config : [];

        if (! self::bool($config, 'enabled', true)) {
            return null;
        }

        $defaults = new GraphQlPolicy;
        $flag = static fn (string $key, ?bool $default): ?bool => array_key_exists($key, $config) ? ($config[$key] === null ? null : self::bool($config, $key, (bool) $default)) : $default;
        $number = static fn (string $key, ?int $default): ?int => array_key_exists($key, $config) ? ($config[$key] === null ? null : max(0, self::int($config, $key, (int) $default))) : $default;

        return new GraphQlPolicy(
            introspection: $flag('introspection', $defaults->introspection),
            debug: $flag('debug', $defaults->debug),
            batchLimit: $number('batch_limit', $defaults->batchLimit),
            maxDepth: $number('max_depth', $defaults->maxDepth),
            graphiql: $flag('graphiql', $defaults->graphiql),
            restrictToLoggedIn: $flag('restrict_to_logged_in', $defaults->restrictToLoggedIn),
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
     * @param  array<mixed>  $config
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

    /**
     * Where the front end handles previews, or null when previews are off or there is no front end.
     *
     * @param  array<mixed>  $config
     */
    private static function previewUrl(array $config, ?Origin $front): ?string
    {
        if (! $front instanceof Origin || ! self::bool($config, 'enabled', true)) {
            return null;
        }

        $url = self::string($config, 'url', '/api/preview');

        return str_starts_with($url, '/') ? $front->toString().$url : Origin::fromUrl($url)->toString().parse_url($url, PHP_URL_PATH);
    }

    /**
     * The targets of the revalidation notifications; none when it is switched off.
     *
     * @param  array<mixed>  $config
     * @return list<Target>
     *
     * @throws InvalidSettingsException when a target has no secret: an unsigned notification would be accepted by anyone
     */
    private static function targets(array $config): array
    {
        if (! self::bool($config, 'enabled', true)) {
            return [];
        }

        $targets = [];

        foreach (is_array($config['targets'] ?? null) ? $config['targets'] : [] as $index => $target) {
            $target = is_array($target) ? $target : [];
            $url = self::string($target, 'url');
            $secret = self::string($target, 'secret');

            if ($url === '') {
                continue;
            }

            Origin::fromUrl($url);

            if ($secret === '') {
                throw InvalidSettingsException::missing('revalidation.targets.'.$index.'.secret');
            }

            $targets[] = new Target(self::string($target, 'name', 'target-'.$index) ?: 'target-'.$index, $url, $secret);
        }

        return $targets;
    }

    /**
     * The address the CDN serves the uploads from, without its trailing slash; null when there is none.
     *
     * @param  array<mixed>  $config
     */
    private static function mediaCdn(array $config): ?string
    {
        $url = rtrim(self::string($config, 'cdn_url'), '/');

        if ($url === '') {
            return null;
        }

        Origin::fromUrl($url);

        return $url;
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
     * @param  array<mixed>  $config
     */
    private static function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function bool(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<mixed>  $config
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
