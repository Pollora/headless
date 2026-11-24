<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Runs work later, off the request that saved the post.
 */
interface Scheduler
{
    /** Sends what is pending in `$delay` seconds. Asking again while one is waiting changes nothing. */
    public function scheduleFlush(int $delay): void;

    /** Sends a failed notification again in `$delay` seconds. */
    public function scheduleRetry(string $target, string $deliveryId, int $attempt, int $delay): void;
}
