<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * How long to wait before sending a notification again after a failure, and when to give up.
 *
 * 30 seconds, 2 minutes, 10 minutes, 1 hour, 6 hours: a front end that is restarting is back in minutes,
 * one that is down for the night is reached in the morning.
 */
final readonly class Backoff
{
    private const array DELAYS = [30, 120, 600, 3600, 21600];

    public function __construct(private int $maxAttempts = 5) {}

    /**
     * Seconds to wait after the given failed attempt (the first is 1), or null when no attempt is left.
     */
    public function delayAfter(int $attempt): ?int
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }

        return self::DELAYS[min($attempt, count(self::DELAYS)) - 1];
    }
}
