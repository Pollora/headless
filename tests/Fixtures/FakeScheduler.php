<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Revalidation\Scheduler;

/**
 * An in-memory stand-in for a revalidation port, for the unit tests.
 */
final class FakeScheduler implements Scheduler
{
    /** @var list<int> */
    public array $flushes = [];

    /** @var list<array{string, string, int, int}> */
    public array $retries = [];

    public function scheduleFlush(int $delay): void
    {
        $this->flushes[] = $delay;
    }

    public function scheduleRetry(string $target, string $deliveryId, int $attempt, int $delay): void
    {
        $this->retries[] = [$target, $deliveryId, $attempt, $delay];
    }
}
