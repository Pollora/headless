<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Revalidation\DeliveryStore;

/**
 * An in-memory stand-in for a revalidation port, for the unit tests.
 */
final class FakeDeliveries implements DeliveryStore
{
    /** @var array<string, string> */
    public array $bodies = [];

    public function save(string $id, string $body, int $seconds): void
    {
        $this->bodies[$id] = $body;
    }

    public function load(string $id): ?string
    {
        return $this->bodies[$id] ?? null;
    }

    public function forget(string $id): void
    {
        unset($this->bodies[$id]);
    }
}
