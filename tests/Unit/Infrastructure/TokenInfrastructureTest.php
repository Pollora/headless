<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\UserMetaTokenStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\TokenCommand;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

afterEach(function (): void {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
});

function userMetaStore(): array
{
    $meta = new ArrayObject;
    Functions\when('get_user_meta')->alias(fn (int $user, string $key) => $meta[$user.$key] ?? '');
    Functions\when('update_user_meta')->alias(function (int $user, string $key, mixed $value) use ($meta): bool {
        $meta[$user.$key] = $value;

        return true;
    });

    return [new UserMetaTokenStore, $meta];
}

function storedToken(int $user = 7, string $id = '0123456789ab'): Token
{
    return new Token($id, $user, 'a', 'hash', [TokenScope::Read], [], 1);
}

it('keeps a user\'s tokens in one protected meta and reads them back', function (): void {
    [$store, $meta] = userMetaStore();

    $store->save(storedToken(7, 'aaaaaaaaaaaa'));
    $store->save(storedToken(7, 'bbbbbbbbbbbb'));
    $store->save(storedToken(8, 'cccccccccccc'));

    expect(array_keys($meta->getArrayCopy()))->toBe(['7_headless_tokens', '8_headless_tokens'])
        ->and($store->find(7, 'bbbbbbbbbbbb')?->id)->toBe('bbbbbbbbbbbb')
        ->and($store->find(8, 'aaaaaaaaaaaa'))->toBeNull()
        ->and($store->forUser(7))->toHaveCount(2);

    $store->delete(7, 'aaaaaaaaaaaa');

    expect($store->forUser(7))->toHaveCount(1);
});

it('ignores a meta that is not what it wrote', function (): void {
    [$store, $meta] = userMetaStore();
    $meta['7_headless_tokens'] = ['x' => 'not an array', 'y' => ['id' => 'y']];

    expect($store->forUser(7))->toBe([])
        ->and($store->find(7, 'x'))->toBeNull()
        ->and($store->find(7, 'y'))->toBeNull();

    $meta['7_headless_tokens'] = 'broken';

    expect($store->forUser(7))->toBe([]);
});

function authModule(?InMemoryTokenStore $store = null, bool $rest = true): array
{
    $store ??= new InMemoryTokenStore;
    $service = new TokenService($store);

    Functions\when('wp_get_current_user')->justReturn(null);

    return [new TokenAuthentication($service, fn (): bool => $rest), $service, $store];
}

function restCall(string $method = 'GET', string $route = '/wp/v2/posts'): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_method')->andReturn($method);
    $request->shouldReceive('get_route')->andReturn($route);

    return $request;
}

it('hooks the three filters', function (): void {
    Filters\expectAdded('determine_current_user')->once()->with(Mockery::type('array'), 20, 1);
    Filters\expectAdded('rest_authentication_errors')->once()->with(Mockery::type('array'), 90, 1);
    Filters\expectAdded('rest_request_before_callbacks')->once()->with(Mockery::type('array'), 10, 3);

    [$module] = authModule();
    $module->register(new WordPressHooks);
});

it('logs a REST request in as the token\'s user', function (): void {
    [$module, $service] = authModule();
    $issued = $service->create(7, 'a', [TokenScope::Read]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$issued->secret;

    expect($module->authenticate(false))->toBe(7)
        ->and($module->reject(null))->toBeNull();
});

it('reads the header some servers rename', function (): void {
    [$module, $service] = authModule();
    $issued = $service->create(7, 'a', [TokenScope::Read]);
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'bearer '.$issued->secret;

    expect($module->authenticate(false))->toBe(7);
});

it('leaves alone a user already found, a request outside REST, a header that is not ours', function (): void {
    [$module, $service] = authModule();
    $issued = $service->create(7, 'a', [TokenScope::Read]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$issued->secret;

    expect($module->authenticate(3))->toBe(3);

    [$outside] = authModule(rest: false);
    expect($outside->authenticate(false))->toBeFalse();

    foreach (['Basic abc', 'Bearer other-token', 'Bearer', 'Bearer a b'] as $header) {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
        expect($module->authenticate(false))->toBeFalse()
            ->and($module->reject(null))->toBeNull();
    }

    unset($_SERVER['HTTP_AUTHORIZATION']);
    expect($module->authenticate(false))->toBeFalse();
});

it('answers 401 to a token that is wrong, revoked or expired, rather than treating it as anonymous', function (): void {
    [$module] = authModule();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer hl.7.0123456789ab.'.str_repeat('A', 43);

    expect($module->authenticate(false))->toBeFalse();

    $error = $module->reject(null);

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_code())->toBe('headless_token_invalid')
        ->and($error->get_error_data())->toBe(['status' => 401])
        ->and($module->reject('earlier'))->toBe('earlier')
        ->and($module->reject(true))->toBeInstanceOf(WP_Error::class);
});

it('keeps a request inside the token\'s scopes', function (): void {
    [$module, $service] = authModule();
    $issued = $service->create(7, 'a', [TokenScope::Read], ['/wp/v2/posts']);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$issued->secret;
    $module->authenticate(false);

    $denied = $module->enforceScopes(null, null, restCall('POST'));
    $wrongRoute = $module->enforceScopes(null, null, restCall('GET', '/wp/v2/users'));

    expect($module->enforceScopes(null, null, restCall('GET')))->toBeNull()
        ->and($denied)->toBeInstanceOf(WP_Error::class)
        ->and($denied->get_error_code())->toBe('headless_token_scope')
        ->and($denied->get_error_data())->toBe(['status' => 403])
        ->and($wrongRoute)->toBeInstanceOf(WP_Error::class);
});

it('does not limit a request that did not use a token', function (): void {
    [$module] = authModule();

    expect($module->enforceScopes('earlier', null, restCall('DELETE')))->toBe('earlier');
});

it('uses the real REST_REQUEST constant by default', function (): void {
    $module = new TokenAuthentication(new TokenService(new InMemoryTokenStore));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer hl.7.0123456789ab.'.str_repeat('A', 43);

    // The unit suite defines no REST_REQUEST: the request is not a REST one, so nothing is read
    expect($module->authenticate(false))->toBeFalse()->and($module->reject(null))->toBeNull();
});

function recording(): Output
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

function tokenCommand(Output $output, ?InMemoryTokenStore $store = null): TokenCommand
{
    $users = ['7' => 7, 'editor' => 7, 'ed@example.com' => 7];

    return new TokenCommand(
        new TokenService($store ?? new InMemoryTokenStore, fn (): int => 1_000_000),
        fn (string $who): ?int => $users[$who] ?? null,
        fn (): int => 1_000_000,
        $output,
    );
}

it('creates a token and shows its secret once', function (): void {
    $output = recording();
    $store = new InMemoryTokenStore;

    tokenCommand($output, $store)->create(['editor'], ['name' => 'Next.js', 'scopes' => 'read, write', 'routes' => '/wp/v2/posts', 'expires' => '30d']);

    $token = array_values($store->tokens)[0];

    expect($output->messages[0])->toContain('created')->toContain($token->id)
        ->and($output->messages[1])->toStartWith('line: hl.7.'.$token->id.'.')
        ->and($token->scopes)->toBe([TokenScope::Read, TokenScope::Write])
        ->and($token->routes)->toBe(['/wp/v2/posts'])
        ->and($token->expiresAt)->toBe(1_000_000 + 2592000)
        ->and($token->name)->toBe('Next.js');
});

it('creates a read token without options', function (): void {
    $store = new InMemoryTokenStore;
    tokenCommand(recording(), $store)->create(['7']);

    $token = array_values($store->tokens)[0];

    expect($token->scopes)->toBe([TokenScope::Read])->and($token->name)->toBe('API token')->and($token->expiresAt)->toBeNull();
});

it('says what is wrong with a command', function (): void {
    $store = new InMemoryTokenStore;
    $unknown = recording();
    $badScope = recording();
    $badDuration = recording();

    tokenCommand($unknown, $store)->create(['nobody']);
    tokenCommand($badScope, $store)->create(['7'], ['scopes' => 'admin']);
    tokenCommand($badDuration, $store)->create(['7'], ['expires' => 'soon']);

    expect($unknown->messages)->toBe(['error: No user "nobody".'])
        ->and($badScope->messages[0])->toContain('not a token scope')
        ->and($badDuration->messages[0])->toContain('not a duration')
        ->and($store->tokens)->toBe([]);
});

it('lists tokens without their secrets', function (): void {
    $store = new InMemoryTokenStore;
    $command = tokenCommand(recording(), $store);
    $command->create(['7'], ['name' => 'a', 'expires' => '1d']);
    $store->save(new Token('expired00000', 7, 'old', 'h', [TokenScope::Write], ['/x', '/y'], 1, 5));

    $output = recording();
    tokenCommand($output, $store)->list_(['7']);
    $empty = recording();
    tokenCommand($empty)->list_(['7']);
    $nobody = recording();
    tokenCommand($nobody)->list_(['nobody']);

    expect(implode("\n", $output->messages))->toContain('a  [read]  all routes  expires')
        ->toContain('old  [write]  /x,/y  expired')
        ->and($empty->messages)->toBe(['line: No token.'])
        ->and($nobody->messages)->toBe(['error: No user "nobody".']);
});

it('revokes a token, and says when it is not the user\'s', function (): void {
    $store = new InMemoryTokenStore;
    tokenCommand(recording(), $store)->create(['7']);
    $id = array_values($store->tokens)[0]->id;

    $done = recording();
    tokenCommand($done, $store)->revoke(['7', $id]);
    $missing = recording();
    tokenCommand($missing, $store)->revoke(['7', 'nope']);
    $nobody = recording();
    tokenCommand($nobody, $store)->revoke(['nobody', 'x']);

    expect($done->messages)->toBe(['success: Token revoked.'])
        ->and($store->tokens)->toBe([])
        ->and($missing->messages)->toBe(['error: No token "nope" for this user.'])
        ->and($nobody->messages)->toBe(['error: No user "nobody".']);
});

it('rotates a token with a grace period', function (): void {
    $store = new InMemoryTokenStore;
    tokenCommand(recording(), $store)->create(['7']);
    $old = array_values($store->tokens)[0];

    $output = recording();
    tokenCommand($output, $store)->rotate(['7', $old->id], ['grace' => '2h']);

    expect($output->messages[0])->toContain('replaces '.$old->id)
        ->and($output->messages[1])->toStartWith('line: hl.7.')
        ->and($store->find(7, $old->id)?->expiresAt)->toBe(1_000_000 + 7200)
        ->and($store->tokens)->toHaveCount(2);
});

it('rotates with a day of grace by default and reports its mistakes', function (): void {
    $store = new InMemoryTokenStore;
    tokenCommand(recording(), $store)->create(['7']);
    $old = array_values($store->tokens)[0];

    tokenCommand(recording(), $store)->rotate(['7', $old->id]);
    $badGrace = recording();
    tokenCommand($badGrace, $store)->rotate(['7', $old->id], ['grace' => 'later']);
    $missing = recording();
    tokenCommand($missing, $store)->rotate(['7', 'nope']);
    $nobody = recording();
    tokenCommand($nobody, $store)->rotate(['nobody', 'x']);

    expect($store->find(7, $old->id)?->expiresAt)->toBe(1_000_000 + 86400)
        ->and($badGrace->messages[0])->toContain('not a duration')
        ->and($missing->messages[0])->toContain('No token')
        ->and($nobody->messages[0])->toContain('No user');
});

it('looks at the token itself when WordPress has not asked for the user yet', function (): void {
    [$module] = authModule();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer hl.1.aaaaaaaaaaaa.'.str_repeat('b', 43);
    // WordPress works the user out when something first asks for it: the module's filter then runs
    Functions\when('wp_get_current_user')->alias(static function () use ($module): void {
        $module->authenticate(false);
    });

    expect($module->reject(null))->toBeInstanceOf(WP_Error::class);
});
