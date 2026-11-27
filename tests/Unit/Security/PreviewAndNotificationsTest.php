<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;
use Pollora\Headless\Domain\Revalidation\Batch;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\WebhookSigner;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\Revalidation\OptionPendingEvents;
use Pollora\Headless\Infrastructure\Revalidation\WpHttpTransport;
use Pollora\Headless\Infrastructure\WordPress\PreviewLinks;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\RevalidationRunner;
use Pollora\Headless\Tests\Fixtures\FakeDeliveries;
use Pollora\Headless\Tests\Fixtures\FakePending;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;
use Pollora\Headless\Tests\Fixtures\FakeScheduler;
use Pollora\Headless\Tests\Fixtures\FakeTransport;
use Pollora\Headless\Tests\Fixtures\RecordingLogger;

use function Pollora\Headless\Tests\Fixtures\isolated;

afterEach(function (): void {
    unset($GLOBALS['post'], $GLOBALS['wpdb']);
});

/** A token signed with the right key around any payload: what only the checks after the signature can refuse. */
function signedPayload(string $json): string
{
    $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $payload = $encode($json);

    return $payload.'.'.$encode(hash_hmac('sha256', $payload, 'key', true));
}

/** @return array{Revalidator, FakeTransport, FakeDeliveries} */
function notifier(): array
{
    $transport = new FakeTransport;
    $deliveries = new FakeDeliveries;
    $revalidator = new Revalidator([new Target('next', 'https://a.test/hook', 'k')], new FakePending, $deliveries, new FakeScheduler, $transport, new RecordingLogger, clock: static fn (): int => 5000);

    return [$revalidator, $transport, $deliveries];
}

it('refuses a rightly signed payload that lacks what a preview token holds', function (string $json): void {
    TokenSigner::verify(signedPayload($json), 'key', 1000, PreviewTokenType::Link);
})->throws(PreviewTokenInvalidException::class)->with([
    'no type' => ['{"u":7,"p":42,"s":1,"a":"x","e":2000,"j":"id"}'],
    'an empty id' => ['{"t":"link","u":7,"p":42,"s":1,"a":"x","e":2000,"j":""}'],
    'a text' => ['"link"'],
]);

it('makes tokens that fit in an address, each with 128 random bits of its own', function (): void {
    $store = new FakePreviewStore;
    $token = (new PreviewService($store, new FakePostAccess, 'key', 'https://www.example.com'))->linkToken(7, 42, 1);
    $claims = TokenSigner::verify($token, 'key', time(), PreviewTokenType::Link);

    expect($token)->toMatch('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/')
        ->and($claims->id)->toMatch('/\A[0-9a-f]{32}\z/');
});

it('keeps WordPress\'s preview link when there is no post to point at', function (): void {
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('user_can')->justReturn(true);
    $module = new PreviewLinks(new PreviewService(new FakePreviewStore, new FakePostAccess, 'key', 'https://www.example.com'), 'https://www.example.com/api/preview');

    expect($module->link('wp-link', null))->toBe('wp-link')
        ->and($module->link('wp-link', (object) ['ID' => 42]))->toBe('wp-link');
});

it('gives the front end the path of the permalink, with its query only when there is one', function (string|false $permalink, string $path): void {
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('user_can')->justReturn(true);
    Functions\when('get_permalink')->justReturn($permalink);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('add_query_arg')->alias(static fn (array $args): string => $args['path']);
    $module = new PreviewLinks(new PreviewService(new FakePreviewStore, new FakePostAccess, 'key', 'https://www.example.com'), 'https://www.example.com/api/preview');

    expect($module->link('wp-link', new WP_Post(['ID' => 42, 'post_type' => 'post'])))->toBe($path);
})->with([
    ['https://www.example.com/blog/hello/', '/blog/hello/'],
    ['https://www.example.com/blog/?p=42', '/blog/?p=42'],
    [false, ''],
]);

/** WordPress answering the preview of post 42, recording which post was set up. */
function overlaySite(ArrayObject $setUp, mixed &$asked = null): void
{
    Functions\when('get_post')->justReturn(new WP_Post(['ID' => 42, 'post_title' => 'Saved']));
    Functions\when('wp_get_post_revision')->alias(static function (mixed $id) use (&$asked): WP_Post {
        $asked = $id;

        return new WP_Post(['ID' => 50, 'post_parent' => 42, 'post_title' => 'Revision']);
    });
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_set_current_user')->justReturn(null);
    Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/posts/42');
    Functions\when('rest_do_request')->justReturn(new WP_REST_Response(['title' => ['raw' => 'T'], 'content' => ['raw' => 'C'], 'excerpt' => ['raw' => 'E']]));
    Functions\when('setup_postdata')->alias(static function (WP_Post $post) use ($setUp): void {
        $setUp[] = $post->post_title;
    });
    Functions\when('get_the_excerpt')->justReturn('Excerpt');
    Functions\when('apply_filters')->returnArg(2);
}

it('shows a revision with what else the fields hold, then sets the earlier post up again', function (): void {
    $setUp = new ArrayObject;
    $asked = null;
    overlaySite($setUp, $asked);
    $GLOBALS['post'] = new WP_Post(['ID' => 1, 'post_title' => 'Before']);

    $data = (new PreviewPostResponse)->build(42, 7, '50')->get_data();

    expect($asked)->toBe(50)
        ->and($data['title'])->toBe(['raw' => 'T', 'rendered' => 'Revision'])
        ->and($data['excerpt'])->toBe(['raw' => 'E', 'rendered' => 'Excerpt'])
        ->and($data['preview']['source'])->toBe('revision')
        ->and($setUp->getArrayCopy())->toBe(['Revision', 'Before']);
});

it('sets no post up again when there was none', function (): void {
    $setUp = new ArrayObject;
    overlaySite($setUp);

    (new PreviewPostResponse)->build(42, 7, '50');

    expect($setUp->getArrayCopy())->toBe(['Revision'])->and($GLOBALS['post'])->toBeNull();
});

it('sends nothing for a retry of a target that is no longer configured', function (): void {
    [$revalidator, $transport, $deliveries] = notifier();
    $deliveries->bodies['gone-abc'] = '{"id":"abc"}';

    $revalidator->retry('gone', 'abc', 2);

    expect($transport->sent)->toBe([]);
});

it('pings with a signed ping event, a first attempt of a delivery of its own', function (): void {
    [$revalidator, $transport] = notifier();

    $revalidator->ping();

    [, $body, $headers] = $transport->sent[0];
    $payload = json_decode($body, true);

    expect($payload['id'])->toMatch('/\A[0-9a-f]{24}\z/')
        ->and($payload['events'][0]['event'])->toBe('ping')
        ->and($payload['tags'])->toBe(['ping'])
        ->and($headers['X-Headless-Attempt'])->toBe('1')
        ->and($headers['X-Headless-Delivery'])->toBe($payload['id'])
        ->and(WebhookSigner::verify($body, 'k', 5000, $headers['X-Headless-Signature'], 5000))->toBeTrue();
});

it('retries only a call that names a target, a delivery and an attempt', function (mixed $target, mixed $id, mixed $attempt, int $sent): void {
    [$revalidator, $transport, $deliveries] = notifier();
    $deliveries->bodies['next-abc'] = '{}';
    $deliveries->bodies['next-5'] = '{}';

    (new RevalidationRunner($revalidator))->retry($target, $id, $attempt);

    expect($transport->sent)->toHaveCount($sent);
})->with([
    'complete' => ['next', 'abc', 2, 1],
    'no target' => [null, 'abc', 2, 0],
    'a number for the delivery' => ['next', 5, 2, 0],
    'a text for the attempt' => ['next', 'abc', '2', 0],
]);

it('posts a notification once, without following redirects, and gives up after ten seconds', function (): void {
    Functions\expect('wp_remote_post')->once()->with('https://a.test/hook', [
        'body' => '{}',
        'headers' => ['X-A' => '1'],
        'timeout' => 10,
        'redirection' => 0,
        'user-agent' => 'Pollora-Headless/1',
    ])->andReturn([]);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);

    expect((new WpHttpTransport)->send(new Target('next', 'https://a.test/hook', 'k'), '{}', ['X-A' => '1'])->delivered)->toBeTrue();
});

it('counts a notification as delivered from 200 to 299 only', function (int|string $code, bool $delivered): void {
    Functions\when('wp_remote_post')->justReturn([]);
    Functions\when('wp_remote_retrieve_response_code')->justReturn($code);

    $result = (new WpHttpTransport)->send(new Target('next', 'https://a.test/hook', 'k'), '{}', []);

    expect($result->delivered)->toBe($delivered)->and($result->status)->toBe((int) $code);
})->with([[199, false], [200, true], ['204', true], [299, true], [300, false]]);

it('says a batch is incomplete only past a limit, and cuts it there', function (string $what, int $limit): void {
    $of = static function (int $count) use ($what): Batch {
        $items = array_map(static fn (int $i): string => 'https://a.test/'.$i, range(1, $count));

        return Batch::of(match ($what) {
            'tags' => [new ChangeEvent('x', $items)],
            'urls' => [new ChangeEvent('x', [], $items)],
            'events' => array_map(static fn (): ChangeEvent => new ChangeEvent('x', ['t']), $items),
        });
    };

    expect($of($limit)->truncated)->toBeFalse()
        ->and($of($limit)->{$what})->toHaveCount($limit)
        ->and($of($limit + 1)->truncated)->toBeTrue()
        ->and($of($limit + 1)->{$what})->toHaveCount($limit);
})->with([['tags', 200], ['urls', 200], ['events', 100]]);

it('writes a notification a front end reads as it is: addresses unescaped, paths with their queries', function (): void {
    $json = Batch::of([new ChangeEvent('x', ['café'], ['https://a.test/été/?p=1', 'https://a.test/b', 'https://other.test/b', 'https://a.test'])])->toJson('id', 1);

    expect($json)->toContain('"café"', 'https://a.test/été/?p=1')
        ->and(json_decode($json, true)['paths'])->toBe(['/été/?p=1', '/b', '']);
});

it('keeps the tags of a stored change as a list', function (): void {
    expect(ChangeEvent::fromArray(['event' => 'x', 'tags' => [1, 'a']])?->tags)->toBe(['a']);
});

it('stores each pending change apart, under a random name, and never autoloaded', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\expect('add_option')->once()->with(
        Mockery::on(static fn (string $name): bool => preg_match('/\Aheadless_ev_[0-9a-f]{16}\z/', $name) === 1),
        '{"event":"x","tags":["a"],"urls":[],"post":null,"site":1,"locale":""}',
        '',
        false,
    );

    (new OptionPendingEvents)->push(new ChangeEvent('x', ['a']));
});

it('takes at most 500 pending changes, oldest first, from its own rows', function (): void {
    $wpdb = Mockery::mock('wpdb');
    $wpdb->options = 'wp_options';
    $wpdb->shouldReceive('esc_like')->with('headless_ev_')->andReturn('headless\_ev\_');
    $wpdb->shouldReceive('prepare')->once()->with(
        'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d',
        'wp_options',
        'headless\_ev\_%',
        500,
    )->andReturn('sql');
    $wpdb->shouldReceive('get_results')->with('sql')->andReturn([(object) ['option_name' => 'headless_ev_a']]);
    $GLOBALS['wpdb'] = $wpdb;
    Functions\expect('delete_option')->once()->with('headless_ev_a')->andReturn(true);

    expect((new OptionPendingEvents)->drain())->toBe([]);
});

it('delays a flush and a retry from now, with Action Scheduler or WP-Cron', function (string $backend): void {
    $times = new ArrayObject;
    Functions\when('did_action')->justReturn($backend === 'action scheduler' ? 1 : 0);
    Functions\when('as_has_scheduled_action')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(false);
    $record = static function (int $time) use ($times): void {
        $times[] = $time - time();
    };
    Functions\when('as_schedule_single_action')->alias($record);
    Functions\when('wp_schedule_single_event')->alias($record);

    $scheduler = new ActionSchedulerScheduler;
    $scheduler->scheduleFlush(100);
    $scheduler->scheduleRetry('next', 'abc', 2, 300);

    expect($times[0])->toBeGreaterThanOrEqual(99)->toBeLessThanOrEqual(100)
        ->and($times[1])->toBeGreaterThanOrEqual(299)->toBeLessThanOrEqual(300);
})->with(['action scheduler', 'wp-cron']);

it('asks Action Scheduler itself whether it is ready, when it can', function (string $setup, string $expected): void {
    $code = 'function did_action($hook) { return 1; } '.$setup
        .'echo var_export(Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler::actionSchedulerIsReady(), true);';

    expect(isolated($code))->toBe($expected);
})->with([
    'no functions' => ['', 'false'],
    'ready' => ['function as_schedule_single_action() {} class ActionScheduler { public static function is_initialized() { return true; } } ', 'true'],
    'not ready' => ['function as_schedule_single_action() {} class ActionScheduler { public static function is_initialized() { return false; } } ', 'false'],
    'an older copy, after its init' => ['function as_schedule_single_action() {} ', 'true'],
]);
