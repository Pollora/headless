<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Language;

use Pollora\Headless\Domain\Language\Languages;

/**
 * Picks the multilingual plugin the site runs.
 */
final class LanguagesFactory
{
    /** Looks at what is loaded now: call it once the plugins are (plugins_loaded). */
    public static function detect(): Languages
    {
        return self::from(function_exists('pll_languages_list'), defined('ICL_SITEPRESS_VERSION'));
    }

    /** Polylang wins when both are loaded: its functions are the more precise. */
    public static function from(bool $polylang, bool $wpml): Languages
    {
        return match (true) {
            $polylang => new PolylangLanguages,
            $wpml => new WpmlLanguages,
            default => new NoLanguages,
        };
    }
}
