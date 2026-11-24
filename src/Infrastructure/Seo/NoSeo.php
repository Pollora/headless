<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * No SEO plugin: nothing to give.
 */
final class NoSeo implements SeoProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isActive(): bool
    {
        return false;
    }

    public function forPost(int $postId): ?Seo
    {
        return null;
    }

    public function forTerm(int $termId, string $taxonomy): ?Seo
    {
        return null;
    }
}
