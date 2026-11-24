<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Seo;

/**
 * Where the metadata of a page comes from: a SEO plugin.
 */
interface SeoProvider
{
    /** The plugin: "yoast", "aioseo", "seopress", "rankmath" or "none". */
    public function name(): string;

    public function isActive(): bool;

    public function forPost(int $postId): ?Seo;

    public function forTerm(int $termId, string $taxonomy): ?Seo;
}
