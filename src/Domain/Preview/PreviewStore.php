<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Preview;

/**
 * Remembers the one-time tokens that were issued and not yet used.
 */
interface PreviewStore
{
    /** Keeps a token ID until it is used or `$seconds` have passed. */
    public function remember(string $id, int $seconds): void;

    /** Uses a token ID: true the first time, false when it is unknown, used or gone. */
    public function burn(string $id): bool;
}
