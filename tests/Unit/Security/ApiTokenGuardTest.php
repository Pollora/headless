<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Exceptions\InvalidTokenException;
use Pollora\Headless\Domain\Token\ScopePolicy;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\WordPress\RestSecurity;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\UserMetaTokenStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

use function Pollora\Headless\Tests\Fixtures\isolated;

afterEach(function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

/** @return array{TokenAuthentication, TokenService} */
function guardTokenModule(): array
{
    $service = new TokenService(new InMemoryTokenStore, static fn (): int => 10_000);

    return [new TokenAuthentication($service, static fn (): bool => true), $service];
}

it('gives a read token every safe method and no other', function (string $method, bool $allowed): void {
    $token = new Token('0123456789ab', 7, 'a', 'hash', [TokenScope::Read], [], 1);

    expect(ScopePolicy::allows($token, $method, '/wp/v2/posts'))->toBe($allowed);
})->with([['GET', true], ['HEAD', true], ['OPTIONS', true], ['options', true], ['POST', false], ['PUT', false], ['PATCH', false], ['DELETE', false]]);

it('reads a scope whatever its case and the blanks around it', function (): void {
    expect(TokenScope::fromName("  WRITE\n"))->toBe(TokenScope::Write);
});

it('refuses a scope that does not exist', function (): void {
    TokenScope::fromName('admin');
})->throws(InvalidTokenException::class);

it('does not bring an expired token back to life by rotating it', function (): void {
    $now = 10_000;
    $service = new TokenService(new InMemoryTokenStore, function () use (&$now): int {
        return $now;
    });
    $old = $service->create(7, 'a', [TokenScope::Write], [], 60);

    $now += 120;
    $new = $service->rotate(7, $old->token->id, 600);

    expect($service->authenticate($new->secret))->toBeNull()
        ->and($service->authenticate($old->secret))->toBeNull();
});

it('keeps the routes of a stored token as a list', function (): void {
    $token = Token::fromArray(['id' => 'a', 'hash' => 'h', 'user' => 1, 'scopes' => ['read'], 'routes' => [3, '/wp/v2']]);

    expect($token?->routes)->toBe(['/wp/v2']);
});

it('reads a user meta as the single value WordPress keeps', function (): void {
    $token = new Token('0123456789ab', 7, 'a', 'hash', [TokenScope::Read], [], 1);
    Functions\when('get_user_meta')->alias(static function (int $user, string $key, bool $single = false) use ($token): mixed {
        $value = [$token->id => $token->toArray()];

        return $single ? $value : [$value];
    });

    expect((new UserMetaTokenStore)->find(7, $token->id)?->id)->toBe($token->id);
});

it('reads a bearer header with blanks around it', function (): void {
    [$module, $service] = guardTokenModule();
    $_SERVER['HTTP_AUTHORIZATION'] = '  Bearer '.$service->create(7, 'a', [TokenScope::Read])->secret."  \n";

    expect($module->authenticate(false))->toBe(7);
});

it('does not work the current user out again once the token has been looked at', function (string $secret): void {
    [$module, $service] = guardTokenModule();
    $valid = $service->create(7, 'a', [TokenScope::Read])->secret;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.($secret === 'valid' ? $valid : $secret);
    Functions\expect('wp_get_current_user')->never();

    $module->authenticate(false);
    $answer = $module->reject(null);

    expect($answer instanceof WP_Error)->toBe($secret !== 'valid');
})->with(['valid', 'hl.7.0123456789ab.'.str_repeat('A', 43)]);

it('takes a request for REST or GraphQL from what WordPress and WPGraphQL say', function (string $setup, string $expected): void {
    $code = <<<'PHP'
        use Pollora\Headless\Application\Token\TokenService;
        use Pollora\Headless\Domain\Token\TokenScope;
        use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
        use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;
        $service = new TokenService(new InMemoryTokenStore);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$service->create(7, 'a', [TokenScope::Read])->secret;
        echo var_export((new TokenAuthentication($service))->authenticate(false), true);
        PHP;

    expect(isolated($setup.$code))->toBe($expected);
})->with([
    'neither' => ['', 'false'],
    'a REST request' => ["define('REST_REQUEST', true); ", '7'],
    'REST switched off' => ["define('REST_REQUEST', false); ", 'false'],
    'a GraphQL request' => ['function is_graphql_http_request() { return true; } ', '7'],
    'another request with WPGraphQL' => ['function is_graphql_http_request() { return false; } ', 'false'],
]);

it('passes the users guard and the sitemap filter every argument they read', function (): void {
    Filters\expectAdded('rest_pre_dispatch')->once()->with(Mockery::type('array'), 10, 3);
    Filters\expectAdded('wp_sitemaps_add_provider')->once()->with(Mockery::type('array'), 10, 2);

    (new RestSecurity(true, false))->register(new WordPressHooks);
});

it('turns the file editors off only when asked to', function (bool $disallow, string $expected): void {
    $code = 'use Pollora\Headless\Infrastructure\WordPress\RestSecurity; use Pollora\Headless\Infrastructure\WordPress\WordPressHooks; '
        .'(new RestSecurity(false, '.var_export($disallow, true).'))->register(new WordPressHooks); '
        ."echo defined('DISALLOW_FILE_EDIT') ? var_export(DISALLOW_FILE_EDIT, true) : 'undefined';";

    expect(isolated($code))->toBe($expected);
})->with([[true, 'true'], [false, 'undefined']]);

it('leaves a file editor setting the site already made', function (): void {
    $code = "define('DISALLOW_FILE_EDIT', false); "
        .'(new Pollora\Headless\Infrastructure\WordPress\RestSecurity(false, true))->register(new Pollora\Headless\Infrastructure\WordPress\WordPressHooks); '
        .'echo var_export(DISALLOW_FILE_EDIT, true);';

    expect(isolated($code))->toBe('false');
});
