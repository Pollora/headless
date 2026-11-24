<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Revalidation\Backoff;
use Pollora\Headless\Domain\Revalidation\Batch;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\WebhookSigner;

it('signs the timestamp and the raw body, and a receiver can check it', function (): void {
    $body = '{"id":"a"}';
    $signature = WebhookSigner::signature($body, 'secret', 1000);

    expect($signature)->toBe('sha256='.hash_hmac('sha256', '1000.{"id":"a"}', 'secret'))
        ->and(WebhookSigner::verify($body, 'secret', 1000, $signature, 1100))->toBeTrue();
});

it('refuses a wrong secret, an altered body, another timestamp and a stale request', function (): void {
    $signature = WebhookSigner::signature('{"id":"a"}', 'secret', 1000);

    expect(WebhookSigner::verify('{"id":"a"}', 'other', 1000, $signature, 1100))->toBeFalse()
        ->and(WebhookSigner::verify('{"id":"b"}', 'secret', 1000, $signature, 1100))->toBeFalse()
        ->and(WebhookSigner::verify('{"id":"a"}', 'secret', 1001, $signature, 1100))->toBeFalse()
        ->and(WebhookSigner::verify('{"id":"a"}', 'secret', 1000, $signature, 1301))->toBeFalse()
        ->and(WebhookSigner::verify('{"id":"a"}', 'secret', 1000, $signature, 700))->toBeTrue()
        ->and(WebhookSigner::verify('{"id":"a"}', 'secret', 1000, $signature, 699))->toBeFalse();
});

it('puts the signature, the timestamp, the delivery and the attempt in the headers', function (): void {
    $headers = WebhookSigner::headers('{}', 'secret', 1000, 'abc', 2);

    expect($headers)->toBe([
        'Content-Type' => 'application/json',
        'X-Headless-Signature' => WebhookSigner::signature('{}', 'secret', 1000),
        'X-Headless-Timestamp' => '1000',
        'X-Headless-Delivery' => 'abc',
        'X-Headless-Attempt' => '2',
    ]);
});

it('waits longer after each failure and gives up after the last attempt', function (): void {
    $backoff = new Backoff;

    expect([1, 2, 3, 4, 5, 6])->each->toBeInt()
        ->and(array_map($backoff->delayAfter(...), [1, 2, 3, 4, 5, 6]))->toBe([30, 120, 600, 3600, null, null])
        ->and((new Backoff(7))->delayAfter(5))->toBe(21600)
        ->and((new Backoff(7))->delayAfter(6))->toBe(21600)
        ->and((new Backoff(7))->delayAfter(7))->toBeNull()
        ->and((new Backoff(1))->delayAfter(1))->toBeNull();
});

function changed(string $name, array $tags, array $urls = []): ChangeEvent
{
    return new ChangeEvent($name, $tags, $urls);
}

it('merges the events of a few seconds, each tag and URL once', function (): void {
    $batch = Batch::of([
        changed(ChangeEvent::POST_UPDATED, ['post:1', 'type:post'], ['https://www.example.com/a/']),
        changed(ChangeEvent::POST_UPDATED, ['post:2', 'type:post'], ['https://www.example.com/b/?x=1', 'https://www.example.com/a/']),
    ]);
    $body = json_decode($batch->toJson('abc', 1000), true);

    expect($batch->isEmpty())->toBeFalse()
        ->and($batch->tags)->toBe(['post:1', 'type:post', 'post:2'])
        ->and($body['id'])->toBe('abc')
        ->and($body['sent_at'])->toBe(1000)
        ->and($body['truncated'])->toBeFalse()
        ->and($body['events'])->toHaveCount(2)
        ->and($body['tags'])->toBe(['post:1', 'type:post', 'post:2'])
        ->and($body['urls'])->toBe(['https://www.example.com/a/', 'https://www.example.com/b/?x=1'])
        ->and($body['paths'])->toBe(['/a/', '/b/?x=1']);
});

it('says when it is incomplete', function (): void {
    $tags = array_map(static fn (int $i): string => "post:$i", range(1, Batch::MAX_TAGS + 1));
    $batch = Batch::of([changed(ChangeEvent::POST_UPDATED, $tags)]);

    expect($batch->truncated)->toBeTrue()
        ->and($batch->tags)->toHaveCount(Batch::MAX_TAGS)
        ->and(Batch::of([changed('x', ['a'])])->truncated)->toBeFalse()
        ->and(Batch::of(array_fill(0, Batch::MAX_EVENTS + 1, changed('x', ['a'])))->truncated)->toBeTrue()
        ->and(Batch::of([changed('x', [], array_map(static fn (int $i): string => "https://a.test/$i", range(1, Batch::MAX_URLS + 1)))])->truncated)->toBeTrue()
        ->and(Batch::of([])->isEmpty())->toBeTrue();
});

it('goes to an array and back', function (): void {
    $event = new ChangeEvent(ChangeEvent::POST_PUBLISHED, ['post:12'], ['https://www.example.com/x/'], ['id' => 12, 'type' => 'post', 'slug' => 'x', 'old_slug' => 'y', 'status_from' => 'draft', 'status_to' => 'publish'], 2, 'fr_FR');

    expect(ChangeEvent::fromArray($event->toArray()))->toEqual($event);
});

it('does not read an event that is not one, and fills in what is missing', function (): void {
    $bare = ChangeEvent::fromArray(['event' => 'x', 'tags' => ['a', 5], 'post' => ['id' => 'nope'], 'site' => 'two']);

    expect(ChangeEvent::fromArray([]))->toBeNull()
        ->and(ChangeEvent::fromArray(['event' => 5]))->toBeNull()
        ->and($bare?->tags)->toBe(['a'])
        ->and($bare?->post)->toBe(['id' => 0, 'type' => '', 'slug' => '', 'old_slug' => null, 'status_from' => null, 'status_to' => null])
        ->and($bare?->siteId)->toBe(1)
        ->and($bare?->locale)->toBe('')
        ->and(ChangeEvent::fromArray(['event' => 'x', 'post' => 'broken'])?->post)->toBeNull();
});
