<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Language\LanguageUrls;
use Pollora\Headless\Domain\Media\MediaUrls;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;
use Pollora\Headless\Infrastructure\Language\LanguagesFactory;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RankMathRedirects;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\Revalidation\RevalidatorFactory;
use Pollora\Headless\Infrastructure\Seo\SeoProviders;

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
            self::boot(SettingsLoader::load(home_url(), get_current_blog_id()), new WordPressHooks);
        };

        if (did_action('plugins_loaded') > 0) {
            $start();

            return;
        }

        add_action('plugins_loaded', $start, 1);
    }

    public static function boot(Settings $settings, HookRegistrar $hooks, ?Languages $languages = null): void
    {
        foreach (self::modules($settings, $languages ?? LanguagesFactory::detect()) as $module) {
            $module->register($hooks);
        }
    }

    /**
     * @return list<Module>
     */
    public static function modules(Settings $settings, ?Languages $languages = null): array
    {
        $languages ??= new NoLanguages;
        $modules = [
            new CleanUp($settings->cleanHead, $settings->disableXmlRpc),
            new PublicSurface($settings->disableFeeds, $settings->disableSitemaps, $settings->noindexOrigin),
            new RestSecurity($settings->restrictUsers, $settings->disallowFileEdit),
        ];

        if ($settings->cors instanceof CorsPolicy) {
            $modules[] = new Cors($settings->cors);
        }

        if ($settings->apiTokens) {
            $modules[] = new TokenAuthentication(new TokenService(new UserMetaTokenStore));
        }

        if ($settings->previewUrl !== null && $settings->front instanceof Origin) {
            $service = new PreviewService(
                new TransientPreviewStore,
                new WordPressPostAccess,
                $settings->previewSecret ?? hash_hmac('sha256', 'pollora-headless-preview', wp_salt('auth').wp_salt('secure_auth')),
                $settings->front->toString(),
                $settings->previewLinkTtl,
                $settings->previewSessionTtl,
            );
            $modules[] = new PreviewLinks($service, $settings->previewUrl);
            $modules[] = new PreviewRoutes($service, new PreviewPostResponse);
        }

        if ($settings->revalidationTargets !== []) {
            $revalidator = RevalidatorFactory::fromSettings($settings);
            $modules[] = new ChangeWatcher($revalidator, $languages, $settings->revalidationOptions);
            $modules[] = new RevalidationRunner($revalidator);
        }

        if ($settings->manifest && $settings->urls instanceof UrlMapper && $settings->front instanceof Origin) {
            $urls = $languages->isActive()
                ? new LanguageUrls($settings->urls, $settings->backend, $settings->front, $settings->languageFronts, $languages)
                : $settings->urls;
            $modules[] = new ManifestRoutes(new SiteManifest($urls, $settings->front, $languages));
        }

        if ($settings->redirects && $settings->urls instanceof UrlMapper && $settings->front instanceof Origin) {
            $urls ??= $languages->isActive()
                ? new LanguageUrls($settings->urls, $settings->backend, $settings->front, $settings->languageFronts, $languages)
                : $settings->urls;
            $modules[] = new RedirectRoutes(new RedirectCatalog([new OptionRedirectStore, ...(defined('RANK_MATH_VERSION') ? [new RankMathRedirects] : []), ...($settings->oldSlugRedirects ? [new OldSlugRedirects($urls)] : [])]));
        }

        if ($settings->seo && $settings->urls instanceof UrlMapper && $settings->front instanceof Origin) {
            $seoUrls = $languages->isActive()
                ? new LanguageUrls($settings->urls, $settings->backend, $settings->front, $settings->languageFronts, $languages)
                : $settings->urls;
            $modules[] = new SeoField(SeoProviders::detect(), $seoUrls);
        }

        if ($settings->blocks) {
            $modules[] = new BlocksField;
        }

        if ($settings->mediaRoutes) {
            $modules[] = new MediaRoutes(new NormalizedMedia);
        }

        if ($settings->mediaCdn !== null) {
            $modules[] = new MediaCdn(new MediaUrls((string) (wp_upload_dir(null, false)['baseurl'] ?? ''), $settings->mediaCdn));
        }

        if ($settings->restCache instanceof RestCachePolicy || $settings->perPageMax !== null) {
            $modules[] = new RestPerformance($settings->restCache, $settings->perPageMax);
        }

        if ($settings->urls instanceof UrlMapper && $settings->rewriteLinks) {
            $modules[] = new FrontLinks(
                $settings->urls,
                $settings->rewriteContentLinks ? new ContentLinks($settings->urls, basePath: $settings->basePath) : null,
            );
        }

        if ($settings->gate->isActive()) {
            $modules[] = new TemplateRedirectGate($settings->gate, null, $settings->basePath);
        }

        return $modules;
    }
}
