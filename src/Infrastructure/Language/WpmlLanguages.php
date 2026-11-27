<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Language;

use Pollora\Headless\Domain\Language\Languages;

/**
 * WPML, through its documented filters (`wpml_*`).
 *
 * WPML is closed source and sold: no automated test can run it, and what is known of the filters
 * comes from its public documentation. This adapter is covered by unit tests only and has to be tried
 * on a real WPML site before 1.0.
 */
final readonly class WpmlLanguages implements Languages
{
    public function plugin(): string
    {
        return 'wpml';
    }

    public function isActive(): bool
    {
        return defined('ICL_SITEPRESS_VERSION');
    }

    public function all(): array
    {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        return is_array($languages) ? array_values(array_map(strval(...), array_keys($languages))) : [];
    }

    public function current(): ?string
    {
        $code = apply_filters('wpml_current_language', null);

        return is_string($code) && $code !== '' && $code !== 'all' ? $code : null;
    }

    public function ofPost(int $postId): ?string
    {
        $details = apply_filters('wpml_post_language_details', null, $postId);
        $code = is_array($details) ? ($details['language_code'] ?? null) : null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function translations(int $postId): array
    {
        $trid = apply_filters('wpml_element_trid', null, $postId, 'post_'.get_post_type($postId));
        $elements = apply_filters('wpml_get_element_translations', null, $trid, 'post_'.get_post_type($postId));
        $translations = [];

        foreach (is_array($elements) ? $elements : [] as $code => $element) {
            if (is_object($element) && isset($element->element_id) && is_numeric($element->element_id)) {
                $translations[(string) $code] = (int) $element->element_id;
            }
        }

        return $translations;
    }

    public function homeUrl(string $code): ?string
    {
        $url = apply_filters('wpml_permalink', home_url('/'), $code);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
