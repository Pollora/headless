<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Keeps the body of a notification that failed, until its retries are done.
 */
interface DeliveryStore
{
    public function save(string $id, string $body, int $seconds): void;

    public function load(string $id): ?string;

    public function forget(string $id): void;
}
