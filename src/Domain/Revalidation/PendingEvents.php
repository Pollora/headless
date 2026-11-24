<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * The changes waiting for the next notification.
 */
interface PendingEvents
{
    public function push(ChangeEvent $event): void;

    /**
     * Takes every waiting change, each one by exactly one caller.
     *
     * @return list<ChangeEvent>
     */
    public function drain(): array;
}
