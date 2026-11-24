<?php

declare(strict_types=1);

use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\UserMetaTokenStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * API tokens against a real WordPress: real users, real user meta, the real REST server.
 */
function tokenUser(): int
{
    return get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
}

function tokenModule(): TokenService
{
    $service = new TokenService(new UserMetaTokenStore);
    (new TokenAuthentication($service, static fn (): bool => true))->register(new WordPressHooks);

    return $service;
}

/**
 * Runs a REST request the way the server does: who the request is, then the request itself.
 */
function asToken(string $secret, string $method, string $route, array $body = []): WP_REST_Response
{
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$secret;
    wp_set_current_user(0);
    wp_set_current_user((int) apply_filters('determine_current_user', false));

    $request = new WP_REST_Request($method, $route);
    $request->set_body_params($body);

    $errors = apply_filters('rest_authentication_errors', null);
    $response = $errors instanceof WP_Error ? rest_convert_error_to_response($errors) : rest_do_request($request);

    wp_set_current_user(0);
    unset($_SERVER['HTTP_AUTHORIZATION']);

    return $response;
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

    delete_user_meta(tokenUser(), UserMetaTokenStore::META_KEY);
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

it('keeps the hash in a user meta, never the secret', function (): void {
    $service = tokenModule();
    $issued = $service->create(tokenUser(), 'Next.js', [TokenScope::Read]);

    $stored = get_user_meta(tokenUser(), UserMetaTokenStore::META_KEY, true);

    expect($stored)->toHaveKey($issued->token->id)
        ->and(serialize($stored))->not->toContain(explode('.', $issued->secret)[3])
        ->and(is_protected_meta(UserMetaTokenStore::META_KEY, 'user'))->toBeTrue();
});

it('lets a read token read as its user, and no more', function (): void {
    $issued = tokenModule()->create(tokenUser(), 'reader', [TokenScope::Read]);

    $read = asToken($issued->secret, 'GET', '/wp/v2/users/me');
    $write = asToken($issued->secret, 'POST', '/wp/v2/posts', ['title' => 'Nope', 'status' => 'draft']);

    expect($read->get_status())->toBe(200)
        ->and($read->get_data()['id'])->toBe(tokenUser())
        ->and($write->get_status())->toBe(403)
        ->and($write->get_data()['code'])->toBe('headless_token_scope');
});

it('lets a write token create a post', function (): void {
    $issued = tokenModule()->create(tokenUser(), 'writer', [TokenScope::Write]);

    $created = asToken($issued->secret, 'POST', '/wp/v2/posts', ['title' => 'From a token', 'status' => 'draft']);
    $id = $created->get_data()['id'] ?? 0;
    wp_delete_post($id, true);

    expect($created->get_status())->toBe(201)->and($id)->toBeGreaterThan(0);
});

it('keeps a token to its routes', function (): void {
    $issued = tokenModule()->create(tokenUser(), 'posts only', [TokenScope::Read], ['/wp/v2/posts']);

    expect(asToken($issued->secret, 'GET', '/wp/v2/posts')->get_status())->toBe(200)
        ->and(asToken($issued->secret, 'GET', '/wp/v2/users/me')->get_status())->toBe(403);
});

it('answers 401 to a revoked or unknown token', function (): void {
    $service = tokenModule();
    $issued = $service->create(tokenUser(), 'gone', [TokenScope::Read]);
    $service->revoke(tokenUser(), $issued->token->id);

    $revoked = asToken($issued->secret, 'GET', '/wp/v2/users/me');
    $unknown = asToken('hl.'.tokenUser().'.0123456789ab.'.str_repeat('A', 43), 'GET', '/wp/v2/users/me');

    expect($revoked->get_status())->toBe(401)
        ->and($revoked->get_data()['code'])->toBe('headless_token_invalid')
        ->and($unknown->get_status())->toBe(401);
});

it('does not touch a request that carries no token', function (): void {
    tokenModule();
    wp_set_current_user(0);

    expect(apply_filters('determine_current_user', false))->toBeFalse()
        ->and(apply_filters('rest_authentication_errors', null))->not->toBeInstanceOf(WP_Error::class);
});
