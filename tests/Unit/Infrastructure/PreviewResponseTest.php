<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Preview\PostAccess;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\PreviewRoutes;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;

/*
 * The preview API as a front end sees it, and what it must never give away.
 */

afterEach(function (): void {
    unset($GLOBALS['post']);
});

function previewApi(?PostAccess $posts = null, ?PreviewService &$service = null): PreviewRoutes
{
    $service = new PreviewService(new FakePreviewStore, $posts ?? new FakePostAccess, 'key', 'https://www.example.com');

    return new PreviewRoutes($service, new PreviewPostResponse);
}

/**
 * @param  array<string, mixed>  $params
 */
function previewRequest(array $params = [], ?string $authorization = null): WP_REST_Request
{
    $request = new WP_REST_Request('GET', '/headless/v1/preview');

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    if ($authorization !== null) {
        $request->set_header('authorization', $authorization);
    }

    return $request;
}

/**
 * WordPress as the preview sees it: one saved post, its autosave and revisions, and who the current user is.
 *
 * @param  ArrayObject<int, int>  $users  every user the code switched to
 */
function previewSite(ArrayObject $users, ?WP_Post $autosave = null, ?WP_REST_Response $rest = null): WP_Post
{
    $post = new WP_Post(['ID' => 42, 'post_title' => 'Saved title', 'post_content' => 'Saved body', 'post_excerpt' => 'Saved excerpt', 'post_status' => 'future', 'post_modified_gmt' => '2026-03-01 10:00:00', 'post_date_gmt' => '2026-04-01 08:00:00']);
    $revision = new WP_Post(['ID' => 50, 'post_parent' => 42, 'post_title' => 'Revision title', 'post_content' => 'Revision body', 'post_excerpt' => 'Revision excerpt', 'post_modified_gmt' => '2026-02-01 09:00:00']);
    $foreign = new WP_Post(['ID' => 51, 'post_parent' => 7, 'post_title' => 'Someone else', 'post_content' => 'secret']);

    Functions\when('get_post')->justReturn($post);
    Functions\when('wp_get_post_autosave')->justReturn($autosave ?? false);
    Functions\when('wp_get_post_revision')->alias(static fn (int $id): ?WP_Post => match ($id) {
        50 => $revision,
        51 => $foreign,
        default => null,
    });
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_set_current_user')->alias(static function (int $id) use ($users): void {
        $users->append($id);
    });
    Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/posts/42');
    Functions\when('rest_do_request')->justReturn($rest ?? new WP_REST_Response(['id' => 42, 'title' => ['rendered' => 'Saved title'], 'content' => ['rendered' => '<p>Saved body</p>', 'protected' => true], 'excerpt' => ['rendered' => 'Saved excerpt']]));
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('get_the_excerpt')->alias(static fn (WP_Post $of): string => $of->post_excerpt);
    Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $hook.':'.$value);

    return $post;
}

it('exchanges a link token for a session of its post, never to be cached', function (): void {
    $routes = previewApi(service: $service);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_post')->justReturn(new WP_Post(['ID' => 42, 'post_type' => 'page', 'post_status' => 'draft']));
    Functions\when('get_permalink')->justReturn('https://www.example.com/?page_id=42');

    $response = $routes->exchange(previewRequest(['token' => $service->linkToken(7, 42, 1)]));
    $data = $response->get_data();

    expect($response->get_status())->toBe(200)
        ->and($response->get_headers())->toBe(['Cache-Control' => 'no-store, private'])
        ->and($data['session'])->toStartWith('hlp.')
        ->and($data['expires_in'])->toBe(1800)
        ->and($data['post'])->toBe(['id' => 42, 'type' => 'page', 'status' => 'draft', 'link' => 'https://www.example.com/?page_id=42'])
        ->and($service->authorize($data['session'], 42, 1)->userId)->toBe(7);
});

it('says nothing of a post that vanished between the exchange and the answer', function (): void {
    $routes = previewApi(service: $service);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_post')->justReturn(null);

    expect($routes->exchange(previewRequest(['token' => $service->linkToken(7, 42, 1)]))->get_data()['post'])->toBe(['id' => 42, 'type' => '', 'status' => '', 'link' => '']);
});

it('refuses on one site a link token and a session made on another', function (): void {
    $routes = previewApi(service: $service);
    Functions\when('get_current_blog_id')->justReturn(2);
    $session = $service->exchange($service->linkToken(7, 42, 1), 1)->token;

    $exchange = $routes->exchange(previewRequest(['token' => $service->linkToken(7, 42, 1)]));
    $read = $routes->authorize(previewRequest(['id' => 42], 'Bearer '.$session));

    expect($exchange->get_error_code())->toBe('preview_token_invalid')
        ->and($read->get_error_code())->toBe('preview_token_invalid')
        ->and($read->get_error_data())->toBe(['status' => 401]);
});

it('does not turn a failure that is not about the preview into a preview error', function (string $step): void {
    $posts = new class implements PostAccess
    {
        public bool $broken = false;

        public function isPreviewable(int $postId): bool
        {
            return $this->broken ? throw new RuntimeException('database is gone') : true;
        }

        public function canEdit(int $userId, int $postId): bool
        {
            return true;
        }
    };
    $routes = previewApi($posts, $service);
    Functions\when('get_current_blog_id')->justReturn(1);
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;
    $link = $service->linkToken(7, 42, 1);
    $posts->broken = true;

    expect(fn () => $step === 'exchange'
        ? $routes->exchange(previewRequest(['token' => $link]))
        : $routes->authorize(previewRequest(['id' => 42], 'Bearer '.$session)))->toThrow(RuntimeException::class, 'database is gone');
})->with(['exchange', 'authorize']);

it('takes the session from a Bearer header in any case, and from nothing else', function (string $header, bool $accepted): void {
    $routes = previewApi(service: $service);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('is_user_logged_in')->justReturn(false);
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;

    $answer = $routes->authorize(previewRequest(['id' => 42], str_replace('{s}', $session, $header)));

    $accepted
        ? expect($answer)->toBeTrue()
        : expect([$answer->get_error_code(), $answer->get_error_data()])->toBe(['preview_token_invalid', ['status' => 401]]);
})->with([
    'plain' => ['Bearer {s}', true],
    'lower case and spaces' => ['  bearer    {s}  ', true],
    'another scheme' => ['Basic {s}', false],
    'no scheme' => ['{s}', false],
    'trailing text' => ['Bearer {s} extra', false],
    'two tokens' => ['Bearer x {s}', false],
]);

it('forgets the user of an earlier check when the next one fails', function (): void {
    $routes = previewApi(service: $service);
    $users = new ArrayObject;
    previewSite($users);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('is_user_logged_in')->justReturn(false);
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;

    expect($routes->authorize(previewRequest(['id' => 42], 'Bearer '.$session)))->toBeTrue();
    $routes->read(previewRequest(['id' => 42, 'rev' => 'live']));
    $routes->authorize(previewRequest(['id' => 42]));
    $routes->read(previewRequest(['id' => 42, 'rev' => 'live']));

    // The first read runs as the session's user, the second as nobody; each puts the previous user back
    expect($users->getArrayCopy())->toBe([7, 3, 0, 3]);
});

it('lays the latest autosave over the saved post, as its user, and never to be cached', function (): void {
    $users = new ArrayObject;
    $autosave = new WP_Post(['ID' => 60, 'post_parent' => 42, 'post_title' => 'Autosave title', 'post_content' => 'Autosave body', 'post_excerpt' => 'Autosave excerpt', 'post_modified_gmt' => '2026-03-01 10:00:00']);
    $post = previewSite($users, $autosave);
    $GLOBALS['post'] = $before = new WP_Post(['ID' => 1]);

    $response = (new PreviewPostResponse)->build(42, 7, 'autosave');
    $data = $response->get_data();

    expect($data['title'])->toBe(['rendered' => 'the_title:Autosave title'])
        ->and($data['content'])->toBe(['rendered' => 'the_content:Autosave body', 'protected' => false])
        ->and($data['excerpt'])->toBe(['rendered' => 'the_excerpt:Autosave excerpt'])
        ->and($data['preview'])->toBe(['source' => 'autosave', 'status' => 'future', 'modified_gmt' => '2026-03-01T10:00:00', 'scheduled_gmt' => '2026-04-01T08:00:00'])
        ->and($response->get_headers())->toBe(['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization', 'X-Robots-Tag' => 'noindex, nofollow'])
        ->and($users->getArrayCopy())->toBe([7, 3])
        ->and($post->post_title)->toBe('Saved title')
        ->and($GLOBALS['post'])->toBe($before);
});

it('shows the saved post when asked for it, or when the autosave is older or not there', function (string $revision, bool $stale): void {
    $users = new ArrayObject;
    $autosave = $stale ? new WP_Post(['ID' => 60, 'post_title' => 'Old autosave', 'post_modified_gmt' => '2026-02-28 10:00:00']) : null;
    $post = previewSite($users, $autosave);
    $post->post_status = 'draft';

    $data = (new PreviewPostResponse)->build(42, 7, $revision)->get_data();

    expect($data['title'])->toBe(['rendered' => 'Saved title'])
        ->and($data['preview'])->toBe(['source' => 'live', 'status' => 'draft', 'modified_gmt' => '2026-03-01T10:00:00', 'scheduled_gmt' => null]);
})->with([
    'live' => ['live', true],
    'stale autosave' => ['autosave', true],
    'no autosave' => ['autosave', false],
]);

it('lays a revision of the post over it', function (): void {
    previewSite(new ArrayObject);

    $data = (new PreviewPostResponse)->build(42, 7, '50')->get_data();

    expect($data['title'])->toBe(['rendered' => 'the_title:Revision title'])
        ->and($data['preview']['source'])->toBe('revision')
        ->and($data['preview']['modified_gmt'])->toBe('2026-02-01T09:00:00');
});

it('gives no revision of another post, nor one that does not exist', function (string $revision): void {
    $users = new ArrayObject;
    previewSite($users);

    $error = (new PreviewPostResponse)->build(42, 7, $revision);

    expect([$error->get_error_code(), $error->get_error_message(), $error->get_error_data()])->toBe(['preview_not_found', 'No such revision for this post.', ['status' => 404]])
        ->and($users->getArrayCopy())->toBe([]);
})->with(['another post\'s' => '51', 'missing' => '99']);

it('answers 404, and gives the user back, when the REST API refuses the post', function (WP_REST_Response $rest): void {
    $users = new ArrayObject;
    previewSite($users, rest: $rest);

    $error = (new PreviewPostResponse)->build(42, 7, 'live');

    expect([$error->get_error_code(), $error->get_error_data()])->toBe(['preview_not_found', ['status' => 404]])
        ->and($users->getArrayCopy())->toBe([7, 3]);
})->with([
    'an error' => [new WP_REST_Response(['code' => 'rest_forbidden'], 403)],
    'not an object' => [new WP_REST_Response('text')],
]);

it('puts the current user and post back when a content filter fails', function (): void {
    $users = new ArrayObject;
    previewSite($users);
    $GLOBALS['post'] = $before = new WP_Post(['ID' => 1]);
    Functions\when('apply_filters')->alias(static fn () => throw new RuntimeException('a plugin broke'));

    expect(fn () => (new PreviewPostResponse)->build(42, 7, '50'))->toThrow(RuntimeException::class)
        ->and($users->getArrayCopy())->toBe([7, 3])
        ->and($GLOBALS['post'])->toBe($before);
});
