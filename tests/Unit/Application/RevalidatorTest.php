<?php

declare(strict_types=1);

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Revalidation\Backoff;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\WebhookSigner;
use Pollora\Headless\Tests\Fixtures\FakeDeliveries;
use Pollora\Headless\Tests\Fixtures\FakePending;
use Pollora\Headless\Tests\Fixtures\FakeScheduler;
use Pollora\Headless\Tests\Fixtures\FakeTransport;
use Pollora\Headless\Tests\Fixtures\RecordingLogger;

/**
 * @param  list<Target>|null  $targets
 * @return array{Revalidator, FakePending, FakeDeliveries, FakeScheduler, FakeTransport, RecordingLogger, object}
 */
function revalidator(?array $targets = null, int $maxAttempts = 5): array
{
    $pending = new FakePending;
    $deliveries = new FakeDeliveries;
    $scheduler = new FakeScheduler;
    $transport = new FakeTransport;
    $logger = new RecordingLogger;
    $clock = new class
    {
        public int $now = 5000;
    };

    $service = new Revalidator(
        $targets ?? [new Target('next', 'https://www.example.com/api/revalidate', 's3cret'), new Target('cdn', 'https://cdn.example.com/hook', 'other')],
        $pending,
        $deliveries,
        $scheduler,
        $transport,
        $logger,
        new Backoff($maxAttempts),
        3,
        fn (): int => $clock->now,
        static fn (int $length): string => str_repeat("\x07", $length),
    );

    return [$service, $pending, $deliveries, $scheduler, $transport, $logger, $clock];
}

function postEvent(string $name = ChangeEvent::POST_UPDATED): ChangeEvent
{
    return new ChangeEvent($name, ['post:12'], ['https://www.example.com/hello/']);
}

it('stores a change and asks for one delivery a few seconds later', function (): void {
    [$service, $pending, , $scheduler] = revalidator();

    $service->record(postEvent());
    $service->record(postEvent());

    expect($pending->events)->toHaveCount(2)->and($scheduler->flushes)->toBe([3, 3]);
});

it('does nothing without a target', function (): void {
    [$service, $pending, , $scheduler] = revalidator([]);

    $service->record(postEvent());

    expect($service->isActive())->toBeFalse()->and($pending->events)->toBe([])->and($scheduler->flushes)->toBe([]);
});

it('sends one signed batch to every target', function (): void {
    [$service, $pending, , , $transport] = revalidator();
    $service->record(postEvent());
    $service->record(new ChangeEvent(ChangeEvent::TERM_CHANGED, ['term:5']));

    $sent = $service->flush();

    expect($sent)->toBe(2)
        ->and($pending->events)->toBe([])
        ->and($transport->sent)->toHaveCount(2)
        ->and($transport->sent[0][0])->toBe('next')
        ->and($transport->sent[1][0])->toBe('cdn');

    $body = $transport->sent[0][1];
    $headers = $transport->sent[0][2];

    expect(json_decode($body, true)['tags'])->toBe(['post:12', 'term:5'])
        ->and($transport->sent[1][1])->toBe($body)
        ->and($headers['X-Headless-Signature'])->toBe(WebhookSigner::signature($body, 's3cret', 5000))
        ->and($transport->sent[1][2]['X-Headless-Signature'])->toBe(WebhookSigner::signature($body, 'other', 5000))
        ->and($headers['X-Headless-Delivery'])->toBe(str_repeat('07', 12))
        ->and($headers['X-Headless-Attempt'])->toBe('1');
});

it('sends nothing when nothing is pending', function (): void {
    [$service, , , , $transport] = revalidator();

    expect($service->flush())->toBe(0)->and($transport->sent)->toBe([]);
});

it('keeps the body of a failure and schedules a retry for that target only', function (): void {
    [$service, , $deliveries, $scheduler, $transport] = revalidator();
    $transport->answers = [new DeliveryResult(false, 502), new DeliveryResult(true, 200)];
    $service->record(postEvent());

    $service->flush();

    $id = str_repeat('07', 12);

    expect($scheduler->retries)->toBe([['next', $id, 2, 30]])
        ->and(array_keys($deliveries->bodies))->toBe(['next-'.$id]);
});

it('retries with the same delivery, signed again, and forgets the body once it works', function (): void {
    [$service, , $deliveries, $scheduler, $transport, , $clock] = revalidator();
    $transport->answers = [new DeliveryResult(false, 0, 'timeout')];
    $service->record(postEvent());
    $service->flush();
    $id = str_repeat('07', 12);
    $first = $transport->sent[0];
    $transport->sent = [];
    $clock->now += 30;

    $service->retry('next', $id, 2);

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0][1])->toBe($first[1])
        ->and($transport->sent[0][2]['X-Headless-Delivery'])->toBe($id)
        ->and($transport->sent[0][2]['X-Headless-Attempt'])->toBe('2')
        ->and($transport->sent[0][2]['X-Headless-Timestamp'])->toBe('5030')
        ->and($transport->sent[0][2]['X-Headless-Signature'])->not->toBe($first[2]['X-Headless-Signature'])
        ->and($deliveries->bodies)->toBe([])
        ->and($scheduler->retries)->toHaveCount(1);
});

it('waits longer after each failed retry and gives up after the last attempt', function (): void {
    [$service, , $deliveries, $scheduler, $transport, $logger] = revalidator([new Target('next', 'https://www.example.com/api/revalidate', 's3cret')], 3);
    $transport->answers = array_fill(0, 3, new DeliveryResult(false, 503));
    $service->record(postEvent());
    $service->flush();
    $id = str_repeat('07', 12);

    $service->retry('next', $id, 2);
    $service->retry('next', $id, 3);

    expect($scheduler->retries)->toBe([['next', $id, 2, 30], ['next', $id, 3, 120]])
        ->and($deliveries->bodies)->toBe([])
        ->and($logger->warnings)->toHaveCount(1)
        ->and($logger->warnings[0])->toContain('"next"')->toContain('3 attempts')->toContain('HTTP 503');
});

it('says what a transport error was when it gives up', function (): void {
    [$service, , , , $transport, $logger] = revalidator([new Target('next', 'https://www.example.com/api/revalidate', 's3cret')], 1);
    $transport->answers = [new DeliveryResult(false, 0, 'cURL error 28')];
    $service->record(postEvent());

    $service->flush();

    expect($logger->warnings[0])->toContain('cURL error 28');
});

it('does not retry a target that is gone from the settings, or a body that expired', function (): void {
    [$service, , , , $transport] = revalidator();

    $service->retry('removed', 'abc', 2);
    $service->retry('next', 'unknown-delivery', 2);

    expect($transport->sent)->toBe([]);
});

it('pings every target at once without storing or retrying anything', function (): void {
    [$service, $pending, $deliveries, $scheduler, $transport] = revalidator();
    $transport->answers = [new DeliveryResult(true, 200), new DeliveryResult(false, 401)];

    $results = $service->ping();

    expect(array_keys($results))->toBe(['next', 'cdn'])
        ->and($results['next']->delivered)->toBeTrue()
        ->and($results['cdn']->status)->toBe(401)
        ->and(json_decode($transport->sent[0][1], true)['events'][0]['event'])->toBe('ping')
        ->and($pending->events)->toBe([])
        ->and($deliveries->bodies)->toBe([])
        ->and($scheduler->retries)->toBe([]);
});

it('uses the real clock and random bytes by default', function (): void {
    $transport = new FakeTransport;
    $pending = new FakePending;
    $service = new Revalidator([new Target('next', 'https://www.example.com/hook', 'k')], $pending, new FakeDeliveries, new FakeScheduler, $transport, new RecordingLogger);
    $service->record(postEvent());
    $service->flush();

    expect((int) $transport->sent[0][2]['X-Headless-Timestamp'])->toBeGreaterThan(1_700_000_000)
        ->and($transport->sent[0][2]['X-Headless-Delivery'])->toMatch('/\A[0-9a-f]{24}\z/');
});
