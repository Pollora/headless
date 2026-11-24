<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoMapper;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * The SEO plugins the package reads, and the way to tell which one the site runs.
 */
final class SeoProviders
{
    /** Looks at what is loaded now: call it once the plugins are. */
    public static function detect(): SeoProvider
    {
        return self::from(defined('WPSEO_VERSION'), defined('RANK_MATH_VERSION'), function_exists('aioseo'), defined('SEOPRESS_VERSION'));
    }

    public static function from(bool $yoast, bool $rankMath, bool $aioseo, bool $seopress): SeoProvider
    {
        return match (true) {
            $yoast => new JsonFieldProvider('yoast', 'yoast_head_json', SeoMapper::yoast(...)),
            $rankMath => new RankMathProvider,
            $aioseo => new JsonFieldProvider('aioseo', 'aioseo_head_json', SeoMapper::aioseo(...)),
            $seopress => new SeopressProvider,
            default => new NoSeo,
        };
    }
}
