<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\PendingEvents;

/**
 * An in-memory stand-in for a revalidation port, for the unit tests.
 */
final class FakePending implements PendingEvents
{
    /** @var list<ChangeEvent> */
    public array $events = [];

    public function push(ChangeEvent $event): void
    {
        $this->events[] = $event;
    }

    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
