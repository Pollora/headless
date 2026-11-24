<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Language;

use Pollora\Headless\Domain\Language\Languages;

/**
 * Polylang, through its public functions (`pll_*`).
 */
final readonly class PolylangLanguages implements Languages
{
    public function plugin(): string
    {
        return 'polylang';
    }

    public function isActive(): bool
    {
        return function_exists('pll_languages_list');
    }

    public function all(): array
    {
        $codes = pll_languages_list(['fields' => 'slug']);

        return array_values(array_filter(is_array($codes) ? $codes : [], is_string(...)));
    }

    public function current(): ?string
    {
        $code = pll_current_language();

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function ofPost(int $postId): ?string
    {
        $code = pll_get_post_language($postId);

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function translations(int $postId): array
    {
        $translations = pll_get_post_translations($postId);

        return array_map(intval(...), array_filter(is_array($translations) ? $translations : [], is_numeric(...)));
    }

    public function homeUrl(string $code): ?string
    {
        $url = pll_home_url($code);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
