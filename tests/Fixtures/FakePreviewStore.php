<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Preview\PreviewStore;

/**
 * A preview store in memory, for the unit tests.
 */
final class FakePreviewStore implements PreviewStore
{
    /** @var array<string, int> */
    public array $remembered = [];

    public function remember(string $id, int $seconds): void
    {
        $this->remembered[$id] = $seconds;
    }

    public function burn(string $id): bool
    {
        if (! isset($this->remembered[$id])) {
            return false;
        }

        unset($this->remembered[$id]);

        return true;
    }
}
