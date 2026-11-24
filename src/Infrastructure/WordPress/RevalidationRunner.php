<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;

/**
 * Answers the two scheduled actions: send what is pending, and send a failed notification again.
 */
final readonly class RevalidationRunner implements Module
{
    public function __construct(private Revalidator $revalidator) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction(ActionSchedulerScheduler::FLUSH, [$this, 'flush']);
        $hooks->addAction(ActionSchedulerScheduler::RETRY, [$this, 'retry'], 10, 3);
    }

    public function flush(): void
    {
        $this->revalidator->flush();
    }

    public function retry(mixed $target, mixed $deliveryId, mixed $attempt): void
    {
        if (is_string($target) && is_string($deliveryId) && is_int($attempt)) {
            $this->revalidator->retry($target, $deliveryId, $attempt);
        }
    }
}
