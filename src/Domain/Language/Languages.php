<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Language;

/**
 * What the package needs to know from a multilingual plugin (Polylang, WPML).
 *
 * Every answer is read when it is asked: the plugin finishes its own setup after the
 * package starts, so nothing is cached at boot.
 */
interface Languages
{
    /** The plugin behind it: "polylang", "wpml" or "none". */
    public function plugin(): string;

    public function isActive(): bool;

    /**
     * The language codes of the site.
     *
     * @return list<string>
     */
    public function all(): array;

    /** The language of the current request, when the plugin knows it. */
    public function current(): ?string;

    public function ofPost(int $postId): ?string;

    /**
     * The translations of a post: language code to post ID, the post itself included.
     *
     * @return array<string, int>
     */
    public function translations(int $postId): array;

    /** The home URL of a language on the WordPress host (the language has its own host in domain mode). */
    public function homeUrl(string $code): ?string;
}
