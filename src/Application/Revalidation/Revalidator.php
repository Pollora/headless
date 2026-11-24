<?php

declare(strict_types=1);

namespace Pollora\Headless\Application\Revalidation;

use Closure;
use Pollora\Headless\Domain\Revalidation\Backoff;
use Pollora\Headless\Domain\Revalidation\Batch;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\DeliveryStore;
use Pollora\Headless\Domain\Revalidation\Logger;
use Pollora\Headless\Domain\Revalidation\PendingEvents;
use Pollora\Headless\Domain\Revalidation\Scheduler;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\Transport;
use Pollora\Headless\Domain\Revalidation\WebhookSigner;

/**
 * Tells the front end what changed, without making the editor wait.
 *
 * `record()` only stores the change and asks for one delivery a few seconds later. `flush()` merges
 * everything that arrived meanwhile into one batch and sends it to each target. A target that does not
 * answer 2xx is tried again with a growing delay (see Backoff), with the same delivery ID, until it
 * works or the attempts are used: delivery is "at least once", and a front end ignores an ID it has seen.
 */
final readonly class Revalidator
{
    /** A failed body is kept this long: past the last retry nothing needs it. */
    private const int KEEP_SECONDS = 172800;

    private Closure $clock;

    private Closure $random;

    /**
     * @param  list<Target>  $targets
     * @param  (callable(): int)|null  $clock  the current time; replaced in tests
     * @param  (callable(int): string)|null  $random  random bytes; replaced in tests
     */
    public function __construct(
        private array $targets,
        private PendingEvents $pending,
        private DeliveryStore $deliveries,
        private Scheduler $scheduler,
        private Transport $transport,
        private Logger $logger,
        private Backoff $backoff = new Backoff,
        private int $debounce = 3,
        ?callable $clock = null,
        ?callable $random = null,
    ) {
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : time(...);
        $this->random = $random !== null ? Closure::fromCallable($random) : random_bytes(...);
    }

    public function isActive(): bool
    {
        return $this->targets !== [];
    }

    /** Stores a change and makes sure a delivery is on its way. */
    public function record(ChangeEvent $event): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->pending->push($event);
        $this->scheduler->scheduleFlush($this->debounce);
    }

    /** Sends what is pending to every target. Returns the number of events sent. */
    public function flush(): int
    {
        $batch = Batch::of($this->pending->drain());

        if ($batch->isEmpty()) {
            return 0;
        }

        $id = bin2hex(($this->random)(12));
        $body = $batch->toJson($id, ($this->clock)());

        foreach ($this->targets as $target) {
            $this->deliver($target, $id, $body, 1);
        }

        return count($batch->events);
    }

    /** Sends a failed notification again. */
    public function retry(string $targetName, string $deliveryId, int $attempt): void
    {
        $target = $this->target($targetName);
        $body = $this->deliveries->load($this->key($targetName, $deliveryId));

        if (! $target instanceof Target || $body === null) {
            return;
        }

        $this->deliver($target, $deliveryId, $body, $attempt);
    }

    /**
     * Sends one signed event to every target, now, and says how each answered. Nothing is stored or retried.
     *
     * @return array<string, DeliveryResult> by target name
     */
    public function ping(): array
    {
        $id = bin2hex(($this->random)(12));
        $body = Batch::of([new ChangeEvent(ChangeEvent::PING, ['ping'])])->toJson($id, ($this->clock)());
        $results = [];

        foreach ($this->targets as $target) {
            $results[$target->name] = $this->transport->send($target, $body, WebhookSigner::headers($body, $target->secret, ($this->clock)(), $id, 1));
        }

        return $results;
    }

    private function deliver(Target $target, string $id, string $body, int $attempt): void
    {
        $now = ($this->clock)();
        $result = $this->transport->send($target, $body, WebhookSigner::headers($body, $target->secret, $now, $id, $attempt));

        if ($result->delivered) {
            $this->deliveries->forget($this->key($target->name, $id));

            return;
        }

        $delay = $this->backoff->delayAfter($attempt);

        if ($delay === null) {
            $this->deliveries->forget($this->key($target->name, $id));
            $this->logger->warning(sprintf('Revalidation of "%s" given up after %d attempts (%s).', $target->name, $attempt, $result->error ?? 'HTTP '.$result->status));

            return;
        }

        $this->deliveries->save($this->key($target->name, $id), $body, self::KEEP_SECONDS);
        $this->scheduler->scheduleRetry($target->name, $id, $attempt + 1, $delay);
    }

    private function target(string $name): ?Target
    {
        foreach ($this->targets as $target) {
            if ($target->name === $name) {
                return $target;
            }
        }

        return null;
    }

    private function key(string $target, string $deliveryId): string
    {
        return $target.'-'.$deliveryId;
    }
}
