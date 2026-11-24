<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Language;

use Pollora\Headless\Domain\Language\Languages;

/**
 * No multilingual plugin: one language, nothing to map.
 */
final readonly class NoLanguages implements Languages
{
    public function plugin(): string
    {
        return 'none';
    }

    public function isActive(): bool
    {
        return false;
    }

    public function all(): array
    {
        return [];
    }

    public function current(): ?string
    {
        return null;
    }

    public function ofPost(int $postId): ?string
    {
        return null;
    }

    public function translations(int $postId): array
    {
        return [];
    }

    public function homeUrl(string $code): ?string
    {
        return null;
    }
}
