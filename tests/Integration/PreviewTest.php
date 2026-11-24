<?php

declare(strict_types=1);

use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Preview\PreviewClaims;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;
use Pollora\Headless\Infrastructure\WordPress\PreviewLinks;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\PreviewRoutes;
use Pollora\Headless\Infrastructure\WordPress\TransientPreviewStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Infrastructure\WordPress\WordPressPostAccess;

/**
 * The preview against a real WordPress: the editor's REST autosave, real users and capabilities, the real REST server.
 */
const PREVIEW_FRONT = 'https://www.example.com';

require_once ABSPATH.'wp-admin/includes/user.php';

function previewAdmin(): int
{
    return get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
}

function previewSubscriber(): int
{
    $existing = get_user_by('login', 'headless_subscriber');

    return $existing instanceof WP_User ? $existing->ID : wp_insert_user(['user_login' => 'headless_subscriber', 'user_pass' => wp_generate_password(), 'user_email' => 'sub@example.test', 'role' => 'subscriber']);
}

function previewService(): PreviewService
{
    return new PreviewService(new TransientPreviewStore, new WordPressPostAccess, 'integration-key', PREVIEW_FRONT);
}

function previewModules(): PreviewService
{
    $service = previewService();
    (new PreviewLinks($service, PREVIEW_FRONT.'/api/preview'))->register(new WordPressHooks);
    (new PreviewRoutes($service, new PreviewPostResponse))->register(new WordPressHooks);

    // Build the REST server again so that it registers the routes
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();

    return $service;
}

function previewPost(string $status = 'draft', string $title = 'Saved title'): int
{
    return wp_insert_post(['post_title' => $title, 'post_content' => '<p>Saved content</p>', 'post_status' => $status, 'post_author' => previewAdmin()]);
}

function previewGet(int $id, ?string $session, string $rev = 'autosave'): WP_REST_Response
{
    $request = new WP_REST_Request('GET', "/headless/v1/preview/posts/$id");
    $request->set_param('rev', $rev);

    if ($session !== null) {
        $request->set_header('authorization', 'Bearer '.$session);
    }

    return rest_do_request($request);
}

function previewExchange(string $token): WP_REST_Response
{
    $request = new WP_REST_Request('POST', '/headless/v1/preview/exchange');
    $request->set_body_params(['token' => $token]);

    return rest_do_request($request);
}

function tokenOf(string $link): string
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

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

    wp_set_current_user(0);
});

it('puts a token in the link of the editor\'s Preview button, which comes from the REST autosave', function (): void {
    previewModules();
    $id = previewPost();
    wp_set_current_user(previewAdmin());

    $request = new WP_REST_Request('POST', "/wp/v2/posts/$id/autosaves");
    $request->set_body_params(['title' => 'Autosaved title', 'content' => '<p>Autosaved content</p>']);
    $autosave = rest_do_request($request)->get_data();
    wp_delete_post($id, true);

    expect($autosave['preview_link'])->toStartWith(PREVIEW_FRONT.'/api/preview?')
        ->and(tokenOf($autosave['preview_link']))->not->toBe('')
        ->and($autosave['preview_link'])->toContain('id='.$id)->toContain('type=post');
});

it('leaves WordPress\'s link to someone who cannot edit the post', function (): void {
    previewModules();
    $id = previewPost();
    wp_set_current_user(previewSubscriber());
    $link = get_preview_post_link($id);
    wp_set_current_user(0);
    $visitor = get_preview_post_link($id);
    wp_delete_post($id, true);

    expect($link)->not->toContain('/api/preview')->and($visitor)->not->toContain('/api/preview');
});

it('previews a draft: WordPress autosaves a draft into the post itself', function (): void {
    previewModules();
    $id = previewPost();
    wp_set_current_user(previewAdmin());

    $autosave = new WP_REST_Request('POST', "/wp/v2/posts/$id/autosaves");
    $autosave->set_body_params(['title' => 'Autosaved title', 'content' => '<p>Autosaved content</p>']);
    $token = tokenOf(rest_do_request($autosave)->get_data()['preview_link']);
    wp_set_current_user(0);

    $exchange = previewExchange($token);
    $preview = previewGet($id, $exchange->get_data()['session']);
    wp_delete_post($id, true);

    expect($exchange->get_status())->toBe(200)
        ->and($exchange->get_data()['post'])->toMatchArray(['id' => $id, 'type' => 'post', 'status' => 'draft'])
        ->and($exchange->get_data()['expires_in'])->toBe(1800)
        ->and($preview->get_status())->toBe(200)
        ->and($preview->get_data()['title']['rendered'])->toBe('Autosaved title')
        ->and($preview->get_data()['status'])->toBe('draft')
        ->and($preview->get_headers()['Cache-Control'])->toBe('no-store, private')
        ->and($preview->get_headers()['Vary'])->toBe('Authorization')
        ->and($preview->get_headers()['X-Robots-Tag'])->toContain('noindex');
});

it('previews a published post with its autosave laid over it, and the saved post on request', function (): void {
    previewModules();
    $id = previewPost('publish');
    wp_set_current_user(previewAdmin());

    $autosave = new WP_REST_Request('POST', "/wp/v2/posts/$id/autosaves");
    $autosave->set_body_params(['title' => 'Autosaved title', 'content' => '<p>Autosaved content</p>']);
    $token = tokenOf(rest_do_request($autosave)->get_data()['preview_link']);
    wp_set_current_user(0);

    $session = previewExchange($token)->get_data()['session'];
    $preview = previewGet($id, $session);
    $live = previewGet($id, $session, 'live');
    wp_delete_post($id, true);

    expect($preview->get_status())->toBe(200)
        ->and($preview->get_data()['title']['rendered'])->toBe('Autosaved title')
        ->and($preview->get_data()['content']['rendered'])->toContain('Autosaved content')
        ->and($preview->get_data()['preview']['source'])->toBe('autosave')
        ->and($live->get_data()['title']['rendered'])->toBe('Saved title')
        ->and($live->get_data()['preview']['source'])->toBe('live');
});

it('previews a scheduled post and a private one', function (): void {
    $service = previewModules();
    $future = wp_insert_post(['post_title' => 'Later', 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + 86400), 'post_author' => previewAdmin()]);
    $private = previewPost('private', 'Secret');

    $sessions = array_map(fn (int $id): string => $service->exchange($service->linkToken(previewAdmin(), $id, 1))->token, [$future, $private]);
    $scheduled = previewGet($future, $sessions[0]);
    $hidden = previewGet($private, $sessions[1]);
    wp_delete_post($future, true);
    wp_delete_post($private, true);

    expect($scheduled->get_status())->toBe(200)
        ->and($scheduled->get_data()['preview']['status'])->toBe('future')
        ->and($scheduled->get_data()['preview']['scheduled_gmt'])->not->toBeNull()
        ->and($hidden->get_status())->toBe(200)
        ->and($hidden->get_data()['title']['rendered'])->toBe('Secret');
});

it('previews a revision', function (): void {
    $service = previewModules();
    $id = previewPost('publish', 'Version one');
    // Not wp_save_post_revision(): a REST autosave earlier in the process defines DOING_AUTOSAVE, which makes it do nothing
    _wp_put_post_revision($id);
    wp_update_post(['ID' => $id, 'post_title' => 'Version two', 'post_content' => '<p>Two</p>']);
    $revisions = wp_get_post_revisions($id);
    $first = array_values(array_filter($revisions, static fn (WP_Post $revision): bool => $revision->post_title === 'Version one'));

    $session = $service->exchange($service->linkToken(previewAdmin(), $id, 1))->token;
    $old = previewGet($id, $session, (string) ($first[0]->ID ?? 0));
    $missing = previewGet($id, $session, '999999');
    wp_delete_post($id, true);

    expect($first)->not->toBeEmpty()
        ->and($old->get_status())->toBe(200)
        ->and($old->get_data()['title']['rendered'])->toBe('Version one')
        ->and($old->get_data()['preview']['source'])->toBe('revision')
        ->and($missing->get_status())->toBe(404);
});

it('answers with a stable code and status to each way a token can fail', function (): void {
    $service = previewModules();
    $id = previewPost();
    $other = previewPost();
    $token = $service->linkToken(previewAdmin(), $id, 1);

    $first = previewExchange($token);
    $again = previewExchange($token);
    $forged = previewExchange('not.a-token');
    $expired = previewExchange(TokenSigner::sign(new PreviewClaims(PreviewTokenType::Link, previewAdmin(), $id, 1, PREVIEW_FRONT, time() - 10, 'old'), 'integration-key'));
    $nobody = previewExchange($service->linkToken(previewSubscriber(), $id, 1));
    $session = $first->get_data()['session'];
    $wrongPost = previewGet($other, $session);
    $noCredentials = previewGet($id, null);
    wp_delete_post($id, true);
    wp_delete_post($other, true);

    expect($first->get_status())->toBe(200)
        ->and([$again->get_status(), $again->get_data()['code']])->toBe([409, 'preview_token_used'])
        ->and([$forged->get_status(), $forged->get_data()['code']])->toBe([401, 'preview_token_invalid'])
        ->and([$expired->get_status(), $expired->get_data()['code']])->toBe([401, 'preview_token_expired'])
        ->and([$nobody->get_status(), $nobody->get_data()['code']])->toBe([403, 'preview_forbidden'])
        ->and([$wrongPost->get_status(), $wrongPost->get_data()['code']])->toBe([403, 'preview_forbidden'])
        ->and([$noCredentials->get_status(), $noCredentials->get_data()['code']])->toBe([401, 'preview_token_invalid']);
});

it('stops a session as soon as the user loses the right to edit the post', function (): void {
    $service = previewModules();
    $id = previewPost();
    $user = previewSubscriber();
    $author = wp_insert_user(['user_login' => 'headless_editor_'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password(6, false).'@example.test', 'role' => 'editor']);
    $session = $service->exchange($service->linkToken($author, $id, 1))->token;

    $before = previewGet($id, $session)->get_status();
    (new WP_User($author))->set_role('subscriber');
    $after = previewGet($id, $session)->get_status();
    wp_delete_post($id, true);
    wp_delete_user($author);

    expect($user)->toBeGreaterThan(0)->and($before)->toBe(200)->and($after)->toBe(403);
});

it('lets a logged-in editor read the preview without a session', function (): void {
    previewModules();
    $id = previewPost();

    wp_set_current_user(previewAdmin());
    $editor = previewGet($id, null, 'live');
    wp_set_current_user(previewSubscriber());
    $subscriber = previewGet($id, null, 'live');
    wp_delete_post($id, true);

    expect($editor->get_status())->toBe(200)->and($subscriber->get_status())->toBe(403);
});

it('does not log the session\'s user in outside the preview request', function (): void {
    $service = previewModules();
    $id = previewPost();
    $session = $service->exchange($service->linkToken(previewAdmin(), $id, 1))->token;

    wp_set_current_user(0);
    previewGet($id, $session);
    $stillAnonymous = get_current_user_id();
    wp_delete_post($id, true);

    expect($stillAnonymous)->toBe(0);
});
