<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoMapper;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * SEOPress, through its REST routes `/seopress/v1/posts/{id}` and `/seopress/v1/terms/{id}`.
 */
final class SeopressProvider implements SeoProvider
{
    public function name(): string
    {
        return 'seopress';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function forPost(int $postId): ?Seo
    {
        return $this->read('/seopress/v1/posts/'.$postId);
    }

    public function forTerm(int $termId, string $taxonomy): ?Seo
    {
        return $this->read('/seopress/v1/terms/'.$termId);
    }

    private function read(string $route): ?Seo
    {
        $data = InternalRest::get($route)['data'] ?? null;

        return is_array($data) ? SeoMapper::seopress($data) : null;
    }
}
