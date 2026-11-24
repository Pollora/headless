<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Revalidation\Backoff;
use Pollora\Headless\Infrastructure\Config\Settings;

/**
 * Builds the revalidation service from the settings, with WordPress behind its ports.
 */
final class RevalidatorFactory
{
    public static function fromSettings(Settings $settings): Revalidator
    {
        return new Revalidator(
            $settings->revalidationTargets,
            new OptionPendingEvents,
            new TransientDeliveryStore,
            new ActionSchedulerScheduler,
            new WpHttpTransport,
            new ErrorLogLogger,
            new Backoff($settings->revalidationAttempts),
            $settings->revalidationDebounce,
        );
    }
}
