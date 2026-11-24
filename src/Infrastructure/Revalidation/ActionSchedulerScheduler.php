<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Domain\Revalidation\Scheduler;

/**
 * Runs the deliveries off the request, with Action Scheduler when it is there and WP-Cron when it is not.
 *
 * The package shares Action Scheduler with whatever else uses it (WooCommerce ships a copy, and so do many
 * plugins): its actions are in the group `headless`, their hooks are `pollora/headless/…`, and nothing here
 * touches an action of another group. Before `action_scheduler_init` its functions exist but cannot store
 * anything, so the scheduler is only used once it is ready.
 */
final class ActionSchedulerScheduler implements Scheduler
{
    public const string GROUP = 'headless';

    public const string FLUSH = 'pollora/headless/flush';

    public const string RETRY = 'pollora/headless/retry';

    private bool $flushAsked = false;

    public static function actionSchedulerIsReady(): bool
    {
        if (! function_exists('as_schedule_single_action')) {
            return false;
        }

        return class_exists('ActionScheduler', false) ? \ActionScheduler::is_initialized() : did_action('action_scheduler_init') > 0;
    }

    public function scheduleFlush(int $delay): void
    {
        // One flush waits at a time: a change that arrives meanwhile joins it
        if ($this->flushAsked) {
            return;
        }

        $this->flushAsked = true;

        if (self::actionSchedulerIsReady()) {
            if (! as_has_scheduled_action(self::FLUSH, null, self::GROUP)) {
                as_schedule_single_action(time() + $delay, self::FLUSH, [], self::GROUP, true);
            }

            return;
        }

        if (! wp_next_scheduled(self::FLUSH)) {
            wp_schedule_single_event(time() + $delay, self::FLUSH);
        }
    }

    public function scheduleRetry(string $target, string $deliveryId, int $attempt, int $delay): void
    {
        $args = [$target, $deliveryId, $attempt];

        if (self::actionSchedulerIsReady()) {
            as_schedule_single_action(time() + $delay, self::RETRY, $args, self::GROUP);

            return;
        }

        wp_schedule_single_event(time() + $delay, self::RETRY, $args);
    }
}
