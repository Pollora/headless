<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\Revalidation\ErrorLogLogger;
use Pollora\Headless\Infrastructure\Revalidation\OptionPendingEvents;
use Pollora\Headless\Infrastructure\Revalidation\RevalidatorFactory;
use Pollora\Headless\Infrastructure\Revalidation\TransientDeliveryStore;
use Pollora\Headless\Infrastructure\Revalidation\WpHttpTransport;
use Pollora\Headless\Infrastructure\WordPress\RevalidationRunner;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\RevalidateCommand;
use Pollora\Headless\Tests\Fixtures\FakeDeliveries;
use Pollora\Headless\Tests\Fixtures\FakePending;
use Pollora\Headless\Tests\Fixtures\FakeScheduler;
use Pollora\Headless\Tests\Fixtures\FakeTransport;
use Pollora\Headless\Tests\Fixtures\RecordingLogger;

it('keeps each change in a row of its own and takes each row once', function (): void {
    $options = new ArrayObject;
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('add_option')->alias(function (string $name, mixed $value) use ($options): bool {
        $options[$name] = $value;

        return true;
    });
    Functions\when('delete_option')->alias(function (string $name) use ($options): bool {
        $had = isset($options[$name]);
        unset($options[$name]);

        return $had;
    });

    $wpdb = Mockery::mock('wpdb');
    $wpdb->options = 'wp_options';
    $wpdb->shouldReceive('esc_like')->with('headless_ev_')->andReturn('headless\_ev\_');
    $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $sql, mixed ...$args): string => $sql.'|'.implode('|', $args));
    $wpdb->shouldReceive('get_results')->andReturnUsing(static function () use ($options): array {
        $rows = [];
        foreach ($options as $name => $value) {
            $rows[] = (object) ['option_name' => $name, 'option_value' => $value];
        }
        $rows[] = (object) ['option_name' => 'headless_ev_broken', 'option_value' => 'not json'];
        $rows[] = (object) ['option_name' => 'headless_ev_taken', 'option_value' => '{"event":"x","tags":[]}'];

        return $rows;
    });
    $GLOBALS['wpdb'] = $wpdb;

    $store = new OptionPendingEvents;
    $store->push(new ChangeEvent(ChangeEvent::POST_UPDATED, ['post:1']));
    $store->push(new ChangeEvent(ChangeEvent::TERM_CHANGED, ['term:2']));

    expect($options)->toHaveCount(2)
        ->and(array_keys($options->getArrayCopy())[0])->toStartWith('headless_ev_');

    $drained = $store->drain();

    expect(array_map(static fn (ChangeEvent $event): string => $event->name, $drained))->toBe([ChangeEvent::POST_UPDATED, ChangeEvent::TERM_CHANGED])
        ->and($options)->toHaveCount(0);

    unset($GLOBALS['wpdb']);
});

it('keeps the body of a failed notification as a transient, under a short key', function (): void {
    $transients = new ArrayObject;
    Functions\when('set_transient')->alias(function (string $key, mixed $value) use ($transients): bool {
        $transients[$key] = $value;

        return true;
    });
    Functions\when('get_transient')->alias(static fn (string $key): mixed => $transients[$key] ?? false);
    Functions\when('delete_transient')->alias(function (string $key) use ($transients): bool {
        unset($transients[$key]);

        return true;
    });

    $store = new TransientDeliveryStore;
    $store->save('next-'.str_repeat('a', 24), '{"id":1}', 600);

    expect($store->load('next-'.str_repeat('a', 24)))->toBe('{"id":1}')
        ->and(array_keys($transients->getArrayCopy())[0])->toStartWith('headless_dl_')
        ->and(strlen(array_keys($transients->getArrayCopy())[0]))->toBeLessThan(45);

    $store->forget('next-'.str_repeat('a', 24));

    expect($store->load('next-'.str_repeat('a', 24)))->toBeNull();
});

it('schedules one flush with Action Scheduler once it is ready, in the group of the package', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\when('as_has_scheduled_action')->justReturn(false);
    Functions\expect('as_schedule_single_action')->once()->with(Mockery::type('int'), 'pollora/headless/flush', [], 'headless', true);

    $scheduler = new ActionSchedulerScheduler;
    $scheduler->scheduleFlush(3);
    $scheduler->scheduleFlush(3);
});

it('does not schedule a flush when one is already waiting', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\when('as_has_scheduled_action')->justReturn(true);
    Functions\expect('as_schedule_single_action')->never();

    (new ActionSchedulerScheduler)->scheduleFlush(3);
});

it('schedules a retry of one target with its delivery and attempt', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\expect('as_schedule_single_action')->once()->with(Mockery::type('int'), 'pollora/headless/retry', ['next', 'abc', 2], 'headless');

    (new ActionSchedulerScheduler)->scheduleRetry('next', 'abc', 2, 30);
});

it('knows Action Scheduler is not ready before its init', function (): void {
    Functions\when('did_action')->justReturn(0);

    expect(ActionSchedulerScheduler::actionSchedulerIsReady())->toBeFalse();
});

it('answers the scheduled actions with the revalidator', function (): void {
    Actions\expectAdded('pollora/headless/flush')->once();
    Actions\expectAdded('pollora/headless/retry')->once()->with(Mockery::type('array'), 10, 3);

    $transport = new FakeTransport;
    $pending = new FakePending;
    $revalidator = new Revalidator([new Target('next', 'https://a.test/hook', 'k')], $pending, new FakeDeliveries, new FakeScheduler, $transport, new RecordingLogger);
    $runner = new RevalidationRunner($revalidator);
    $runner->register(new WordPressHooks);

    $revalidator->record(new ChangeEvent(ChangeEvent::POST_UPDATED, ['post:1']));
    $runner->flush();
    $runner->retry('next', 'unknown', 2);
    $runner->retry('next', 5, 'x');

    expect($transport->sent)->toHaveCount(1);
});

it('reads a 2xx as delivered, and anything else, or an error, as a failure', function (): void {
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $response): int => $response['code']);
    $answers = [['code' => 204], ['code' => 200], ['code' => 301], ['code' => 500], new WP_Error('http_request_failed', 'cURL error 28: timeout')];
    Functions\when('wp_remote_post')->alias(static function (string $url, array $args) use (&$answers): mixed {
        expect($url)->toBe('https://www.example.com/api/revalidate')
            ->and($args['redirection'])->toBe(0)
            ->and($args['headers']['X-Headless-Delivery'])->toBe('abc');

        return array_shift($answers);
    });

    $transport = new WpHttpTransport;
    $target = new Target('next', 'https://www.example.com/api/revalidate', 'k');
    $results = array_map(static fn (): DeliveryResult => $transport->send($target, '{}', ['X-Headless-Delivery' => 'abc']), range(1, 5));

    expect(array_map(static fn (DeliveryResult $result): bool => $result->delivered, $results))->toBe([true, true, false, false, false])
        ->and($results[2]->status)->toBe(301)
        ->and($results[4]->error)->toBe('cURL error 28: timeout')
        ->and($results[4]->status)->toBe(0);
});

it('logs a warning with the name of the package', function (): void {
    $logFile = tempnam(sys_get_temp_dir(), 'headless-log');
    $previous = ini_set('error_log', $logFile);

    (new ErrorLogLogger)->warning('Revalidation of "next" given up');
    ini_set('error_log', $previous === false ? '' : $previous);

    expect(file_get_contents($logFile))->toContain('[pollora/headless] Revalidation of "next" given up');
});

it('builds the revalidator from the settings', function (): void {
    $settings = Settings::fromArray(['revalidation' => ['targets' => [['name' => 'next', 'url' => 'https://www.example.com/api/revalidate', 'secret' => 'k']], 'max_attempts' => 2, 'debounce' => 9]], 'https://cms.example.com');

    expect(RevalidatorFactory::fromSettings($settings)->isActive())->toBeTrue()
        ->and(RevalidatorFactory::fromSettings(Settings::fromArray([], 'https://cms.example.com'))->isActive())->toBeFalse();
});

it('reads the revalidation settings', function (): void {
    $settings = Settings::fromArray(['revalidation' => [
        'targets' => [['name' => 'next', 'url' => 'https://www.example.com/api/revalidate', 'secret' => 'k'], ['url' => 'https://cdn.example.com/hook', 'secret' => 'j'], ['url' => '']],
        'debounce' => '7',
        'max_attempts' => 99,
        'options' => ['my_option', 'blogname'],
    ]], 'https://cms.example.com');

    expect(array_map(static fn (Target $target): string => $target->name, $settings->revalidationTargets))->toBe(['next', 'target-1'])
        ->and($settings->revalidationTargets[1]->secret)->toBe('j')
        ->and($settings->revalidationDebounce)->toBe(7)
        ->and($settings->revalidationAttempts)->toBe(5)
        ->and($settings->revalidationOptions)->toContain('my_option', 'blogname', 'permalink_structure')
        ->and(count(array_keys($settings->revalidationOptions, 'blogname', true)))->toBe(1)
        ->and(Settings::fromArray(['revalidation' => ['enabled' => false, 'targets' => [['url' => 'https://a.test', 'secret' => 'k']]]], 'https://cms.example.com')->revalidationTargets)->toBe([])
        ->and(Settings::fromArray(['revalidation' => ['max_attempts' => 0]], 'https://cms.example.com')->revalidationAttempts)->toBe(1);
});

it('refuses a target without a secret or with an address that is not one', function (array $target): void {
    Settings::fromArray(['revalidation' => ['targets' => [$target]]], 'https://cms.example.com');
})->throws(InvalidArgumentException::class)->with([
    'no secret' => [['url' => 'https://www.example.com/hook']],
    'empty secret' => [['url' => 'https://www.example.com/hook', 'secret' => ' ']],
    'not an address' => [['url' => 'www.example.com/hook', 'secret' => 'k']],
]);

it('names the setting that has no secret', function (): void {
    try {
        Settings::fromArray(['revalidation' => ['targets' => [['url' => 'https://www.example.com/hook']]]], 'https://cms.example.com');
    } catch (InvalidSettingsException $exception) {
        expect($exception->getMessage())->toContain('revalidation.targets.0.secret');

        return;
    }

    throw new RuntimeException('Expected an exception');
});

function pingOutput(): object
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $messages = [];

        public function line(string $message): void
        {
            $this->messages[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->messages[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->messages[] = 'error: '.$message;
        }
    };
}

function pingRevalidator(array $targets, array $answers): Revalidator
{
    $transport = new FakeTransport;
    $transport->answers = $answers;

    return new Revalidator($targets, new FakePending, new FakeDeliveries, new FakeScheduler, $transport, new RecordingLogger);
}

it('pings every target and says how each answered', function (): void {
    $output = pingOutput();
    $targets = [new Target('next', 'https://a.test/hook', 'k'), new Target('cdn', 'https://b.test/hook', 'j')];

    (new RevalidateCommand(fn (): Revalidator => pingRevalidator($targets, [new DeliveryResult(true, 200), new DeliveryResult(false, 401)]), $output))();

    expect($output->messages)->toBe([
        'success: next answered HTTP 200.',
        'warning: cdn did not accept the ping: HTTP 401.',
        'error: 1 target(s) failed.',
    ]);
});

it('reports a transport error of a ping, and a missing target', function (): void {
    $output = pingOutput();
    (new RevalidateCommand(fn (): Revalidator => pingRevalidator([new Target('next', 'https://a.test/hook', 'k')], [new DeliveryResult(false, 0, 'cURL error 6')]), $output))();

    $none = pingOutput();
    (new RevalidateCommand(fn (): Revalidator => pingRevalidator([], []), $none))();

    expect($output->messages[0])->toBe('warning: next did not accept the ping: cURL error 6.')
        ->and($none->messages)->toHaveCount(1)
        ->and($none->messages[0])->toContain('No revalidation target');
});
