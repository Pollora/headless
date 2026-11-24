<?php

declare(strict_types=1);

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Revalidation\Backoff;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\WebhookSigner;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\Revalidation\ErrorLogLogger;
use Pollora\Headless\Infrastructure\Revalidation\OptionPendingEvents;
use Pollora\Headless\Infrastructure\Revalidation\TransientDeliveryStore;
use Pollora\Headless\Infrastructure\Revalidation\WpHttpTransport;
use Pollora\Headless\Infrastructure\WordPress\ChangeWatcher;
use Pollora\Headless\Infrastructure\WordPress\RevalidationRunner;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Tests\Fixtures\FakeTransport;

/**
 * Revalidation against a real WordPress and a real Action Scheduler: real posts, terms, menus, options and files,
 * the real queue of changes, the real scheduler. The HTTP call is the only double (a transport that records it),
 * except in the one test that sends through WordPress's own HTTP API.
 */
const REVALIDATE_SECRET = 'integration-secret';

function revalidation(?FakeTransport $transport = null, int $attempts = 5): array
{
    $transport ??= new FakeTransport;
    $revalidator = new Revalidator(
        [new Target('next', 'https://www.example.com/api/revalidate', REVALIDATE_SECRET)],
        new OptionPendingEvents,
        new TransientDeliveryStore,
        new ActionSchedulerScheduler,
        $transport,
        new ErrorLogLogger,
        new Backoff($attempts),
        0,
    );
    $watcher = new ChangeWatcher($revalidator, new NoLanguages, [...ChangeWatcher::defaultOptions(), 'headless_test_option']);
    $watcher->register(new WordPressHooks);
    (new RevalidationRunner($revalidator))->register(new WordPressHooks);

    return [$revalidator, $watcher, $transport];
}

/** Ends a request the way WordPress does, then sends what was recorded. */
function settle(Revalidator $revalidator, ChangeWatcher $watcher): void
{
    $watcher->commit();
    $revalidator->flush();
}

/** @return array<string, mixed> */
function lastBody(FakeTransport $transport): array
{
    return json_decode($transport->sent[array_key_last($transport->sent)][1], true);
}

function clearRevalidationState(): void
{
    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'headless\\_ev\\_%' OR option_name LIKE '\\_transient\\_headless\\_dl\\_%'");

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions(ActionSchedulerScheduler::FLUSH, [], ActionSchedulerScheduler::GROUP);
        as_unschedule_all_actions(ActionSchedulerScheduler::RETRY, null, ActionSchedulerScheduler::GROUP);
    }
}

beforeEach(function (): void {
    clearRevalidationState();
    update_option('permalink_structure', '/%postname%/');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
});

afterEach(function (): void {
    foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if (is_array($function) && is_object($function[0]) && str_starts_with($function[0]::class, 'Pollora\\Headless\\')) {
                    remove_filter($hook, $function, $priority);
                }
            }
        }
    }

    clearRevalidationState();
    delete_option('headless_test_option');
    update_option('permalink_structure', '');
    $GLOBALS['wp_rewrite']->set_permalink_structure('');
    wp_set_current_user(0);
});

it('has Action Scheduler loaded and ready, as the CI job installs it', function (): void {
    expect(function_exists('as_schedule_single_action'))->toBeTrue()
        ->and(ActionSchedulerScheduler::actionSchedulerIsReady())->toBeTrue();
});

it('announces a post that is published, with its tags and its address', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    $id = wp_insert_post(['post_title' => 'Hello Revalidation', 'post_status' => 'publish']);
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    wp_delete_post($id, true);

    expect($body['events'][0]['event'])->toBe('post.published')
        ->and($body['tags'])->toContain('post:'.$id, 'type:post', 'site:'.get_current_blog_id())
        ->and($body['urls'])->toBe([home_url('/hello-revalidation/')])
        ->and($body['paths'])->toContain('/hello-revalidation/')
        ->and($body['events'][0]['post'])->toMatchArray(['id' => $id, 'type' => 'post', 'slug' => 'hello-revalidation', 'status_from' => 'new', 'status_to' => 'publish']);
});

it('says nothing when a draft is saved', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    $id = wp_insert_post(['post_title' => 'Just a draft', 'post_status' => 'draft']);
    wp_update_post(['ID' => $id, 'post_title' => 'Still a draft']);
    settle($revalidator, $watcher);
    wp_delete_post($id, true);

    expect($transport->sent)->toBe([]);
});

it('announces an update and, when the slug changes, the old address too', function (): void {
    $id = wp_insert_post(['post_title' => 'First Name', 'post_status' => 'publish']);
    [$revalidator, $watcher, $transport] = revalidation();

    wp_update_post(['ID' => $id, 'post_name' => 'second-name']);
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    wp_delete_post($id, true);

    expect($body['events'][0]['event'])->toBe('post.updated')
        ->and($body['events'][0]['post']['old_slug'])->toBe('first-name')
        ->and($body['urls'])->toBe([home_url('/second-name/'), home_url('/first-name/')]);
});

it('announces a post that is trashed, with the address it had', function (): void {
    $id = wp_insert_post(['post_title' => 'Going Away', 'post_status' => 'publish']);
    [$revalidator, $watcher, $transport] = revalidation();

    wp_trash_post($id);
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    wp_delete_post($id, true);

    expect($body['events'][0]['event'])->toBe('post.unpublished')
        ->and($body['events'][0]['post']['status_to'])->toBe('trash')
        ->and($body['events'][0]['post']['slug'])->toBe('going-away')
        ->and($body['urls'])->toBe([home_url('/going-away/')]);
});

it('announces a published post that is deleted for good', function (): void {
    $id = wp_insert_post(['post_title' => 'Deleted Directly', 'post_status' => 'publish']);
    [$revalidator, $watcher, $transport] = revalidation();

    wp_delete_post($id, true);
    settle($revalidator, $watcher);
    $body = lastBody($transport);

    expect($body['events'][0]['event'])->toBe('post.deleted')
        ->and($body['urls'])->toBe([home_url('/deleted-directly/')]);
});

it('tags the categories of a published post', function (): void {
    $term = wp_insert_term('News Desk', 'category');
    [$revalidator, $watcher, $transport] = revalidation();

    $id = wp_insert_post(['post_title' => 'With A Category', 'post_status' => 'publish', 'post_category' => [$term['term_id']]]);
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    wp_delete_post($id, true);
    wp_delete_term($term['term_id'], 'category');

    expect($body['tags'])->toContain('term:'.$term['term_id']);
});

it('announces a term, a menu, a watched option and a file, merged into one notification', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    $term = wp_insert_term('Brand New Term', 'post_tag');
    $menu = wp_create_nav_menu('Headless Menu');
    update_option('blogname', 'A New Name '.wp_generate_password(4, false));
    add_option('headless_test_option', 'x');
    $file = wp_insert_attachment(['post_title' => 'A file', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], 'headless-test.png');
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    $events = array_column($body['events'], 'event');

    wp_delete_attachment($file, true);
    wp_delete_nav_menu($menu);
    wp_delete_term($term['term_id'], 'post_tag');

    expect($transport->sent)->toHaveCount(1)
        ->and($events)->toContain('term.changed', 'menu.changed', 'options.changed', 'media.changed')
        ->and($body['tags'])->toContain('term:'.$term['term_id'], 'taxonomy:post_tag', 'menus', 'menu:'.$menu, 'menu:headless-menu', 'options', 'option:blogname', 'option:headless_test_option', 'media:'.$file);
});

it('ignores an option nobody watches', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    update_option('some_plugin_counter', 5);
    settle($revalidator, $watcher);
    delete_option('some_plugin_counter');

    expect($transport->sent)->toBe([]);
});

it('merges the changes of many posts into one notification', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    $ids = array_map(static fn (int $i): int => wp_insert_post(['post_title' => "Bulk $i", 'post_status' => 'publish']), range(1, 5));
    settle($revalidator, $watcher);
    $body = lastBody($transport);
    array_map(static fn (int $id): mixed => wp_delete_post($id, true), $ids);

    expect($transport->sent)->toHaveCount(1)
        ->and($body['events'])->toHaveCount(5)
        ->and($body['tags'])->toContain('post:'.$ids[0], 'post:'.$ids[4])
        ->and(array_count_values($body['tags'])['type:post'])->toBe(1);
});

it('signs what it sends: a receiver can check the signature with the shared secret', function (): void {
    [$revalidator, $watcher, $transport] = revalidation();

    $id = wp_insert_post(['post_title' => 'Signed', 'post_status' => 'publish']);
    settle($revalidator, $watcher);
    wp_delete_post($id, true);
    [, $body, $headers] = $transport->sent[0];

    expect(WebhookSigner::verify($body, REVALIDATE_SECRET, (int) $headers['X-Headless-Timestamp'], $headers['X-Headless-Signature'], time()))->toBeTrue()
        ->and(WebhookSigner::verify($body, 'wrong', (int) $headers['X-Headless-Timestamp'], $headers['X-Headless-Signature'], time()))->toBeFalse()
        ->and($headers['X-Headless-Delivery'])->toBe(json_decode($body, true)['id']);
});

it('schedules one flush with Action Scheduler, in the group of the package', function (): void {
    [$revalidator, $watcher] = revalidation();
    // The scheduler of this test waits for real: a flush that is pending shows in the queue
    $scheduled = new Revalidator([new Target('next', 'https://www.example.com/api/revalidate', REVALIDATE_SECRET)], new OptionPendingEvents, new TransientDeliveryStore, new ActionSchedulerScheduler, new FakeTransport, new ErrorLogLogger, new Backoff, 30);

    $id = wp_insert_post(['post_title' => 'Queued', 'post_status' => 'publish']);
    $watcher->commit();
    $scheduled->record(new ChangeEvent('post.updated', ['post:1']));
    $scheduled->record(new ChangeEvent('post.updated', ['post:2']));
    $pending = as_get_scheduled_actions(['hook' => ActionSchedulerScheduler::FLUSH, 'group' => ActionSchedulerScheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING], 'ids');
    wp_delete_post($id, true);

    expect($revalidator->isActive())->toBeTrue()->and($pending)->toHaveCount(1);
});

it('tries a failed target again later, with the same delivery, until it answers', function (): void {
    $transport = new FakeTransport;
    $transport->answers = [new DeliveryResult(false, 503), new DeliveryResult(true, 200)];
    [$revalidator, $watcher] = revalidation($transport);

    $id = wp_insert_post(['post_title' => 'Retried', 'post_status' => 'publish']);
    settle($revalidator, $watcher);
    $queued = as_get_scheduled_actions(['hook' => ActionSchedulerScheduler::RETRY, 'group' => ActionSchedulerScheduler::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING], 'ARRAY_A');
    $action = array_values($queued)[0] ?? null;
    $args = $action !== null ? as_get_scheduled_actions(['hook' => ActionSchedulerScheduler::RETRY, 'group' => ActionSchedulerScheduler::GROUP], OBJECT) : [];
    $retryArgs = array_values($args)[0]->get_args();

    do_action(ActionSchedulerScheduler::RETRY, ...$retryArgs);
    wp_delete_post($id, true);

    expect($queued)->toHaveCount(1)
        ->and($retryArgs[0])->toBe('next')
        ->and($retryArgs[2])->toBe(2)
        ->and($transport->sent)->toHaveCount(2)
        ->and($transport->sent[1][1])->toBe($transport->sent[0][1])
        ->and($transport->sent[1][2]['X-Headless-Attempt'])->toBe('2')
        ->and($transport->sent[1][2]['X-Headless-Delivery'])->toBe($transport->sent[0][2]['X-Headless-Delivery']);
});

it('sends through WordPress\'s HTTP API, signed, without following a redirect', function (): void {
    $seen = [];
    add_filter('pre_http_request', static function (mixed $pre, array $args, string $url) use (&$seen): array {
        $seen = ['url' => $url, 'args' => $args];

        return ['response' => ['code' => 202, 'message' => 'Accepted'], 'body' => '', 'headers' => [], 'cookies' => []];
    }, 10, 3);

    $result = (new WpHttpTransport)->send(new Target('next', 'https://www.example.com/api/revalidate', REVALIDATE_SECRET), '{"id":"x"}', WebhookSigner::headers('{"id":"x"}', REVALIDATE_SECRET, time(), 'x', 1));
    remove_all_filters('pre_http_request');

    expect($result->delivered)->toBeTrue()
        ->and($result->status)->toBe(202)
        ->and($seen['url'])->toBe('https://www.example.com/api/revalidate')
        ->and($seen['args']['redirection'])->toBe(0)
        ->and($seen['args']['headers']['X-Headless-Signature'])->toStartWith('sha256=')
        ->and($seen['args']['body'])->toBe('{"id":"x"}');
});

it('reports a target that cannot be reached as a failure', function (): void {
    add_filter('pre_http_request', static fn (): WP_Error => new WP_Error('http_request_failed', 'Connection refused'));

    $result = (new WpHttpTransport)->send(new Target('next', 'https://www.example.com/api/revalidate', 'k'), '{}', []);
    remove_all_filters('pre_http_request');

    expect($result->delivered)->toBeFalse()->and($result->error)->toBe('Connection refused');
});
