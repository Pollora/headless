<?php

declare(strict_types=1);

use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Exceptions\TokenNotFoundException;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

/**
 * @return array{TokenService, InMemoryTokenStore, object}
 */
function tokenService(int $start = 10000): array
{
    $store = new InMemoryTokenStore;
    $clock = new class($start)
    {
        public function __construct(public int $now) {}
    };
    $counter = 0;
    $random = static function (int $length) use (&$counter): string {
        $counter++;

        return str_repeat(chr($counter), $length);
    };

    return [new TokenService($store, fn (): int => $clock->now, $random), $store, $clock];
}

it('creates a token and gives its secret once, storing only the hash', function (): void {
    [$service, $store] = tokenService();

    $issued = $service->create(7, 'Next.js', [TokenScope::Read], ['/wp/v2'], 3600);

    expect($issued->secret)->toStartWith('hl.7.')
        ->and($issued->token->expiresAt)->toBe(13600)
        ->and($store->tokens)->toHaveCount(1)
        ->and(json_encode($store->tokens))->not->toContain(explode('.', $issued->secret)[3]);
});

it('authenticates a good secret and refuses a wrong, unknown or malformed one', function (): void {
    [$service] = tokenService();
    $issued = $service->create(7, 'a', [TokenScope::Read]);
    [$prefix, $user, $id] = explode('.', $issued->secret);

    expect($service->authenticate($issued->secret)?->id)->toBe($issued->token->id)
        ->and($service->authenticate("$prefix.$user.$id.".str_repeat('A', 43)))->toBeNull()
        ->and($service->authenticate("$prefix.8.$id.".explode('.', $issued->secret)[3]))->toBeNull()
        ->and($service->authenticate('hl.garbage'))->toBeNull();
});

it('refuses an expired token', function (): void {
    [$service, , $clock] = tokenService();
    $issued = $service->create(7, 'a', [TokenScope::Read], [], 60);

    expect($service->authenticate($issued->secret))->not->toBeNull();

    $clock->now += 60;

    expect($service->authenticate($issued->secret))->toBeNull();
});

it('writes the last use at most once an hour', function (): void {
    [$service, $store, $clock] = tokenService();
    $issued = $service->create(7, 'a', [TokenScope::Read]);
    $afterCreate = $store->writes;

    $service->authenticate($issued->secret);
    $service->authenticate($issued->secret);

    expect($store->writes)->toBe($afterCreate + 1);

    $clock->now += 3600;
    $service->authenticate($issued->secret);

    expect($store->writes)->toBe($afterCreate + 2)
        ->and($store->find(7, $issued->token->id)?->lastUsedAt)->toBe(13600);
});

it('lists a user\'s tokens and revokes one', function (): void {
    [$service] = tokenService();
    $first = $service->create(7, 'a', [TokenScope::Read]);
    $service->create(7, 'b', [TokenScope::Write]);
    $service->create(8, 'c', [TokenScope::Read]);

    expect($service->list(7))->toHaveCount(2);

    $service->revoke(7, $first->token->id);

    expect($service->list(7))->toHaveCount(1)
        ->and($service->authenticate($first->secret))->toBeNull();
});

it('refuses to revoke or rotate a token that is not the user\'s', function (): void {
    [$service] = tokenService();
    $other = $service->create(8, 'c', [TokenScope::Read]);

    expect(fn () => $service->revoke(7, $other->token->id))->toThrow(TokenNotFoundException::class)
        ->and(fn () => $service->rotate(7, $other->token->id, 60))->toThrow(TokenNotFoundException::class);
});

it('rotates: a new token like the old one, the old one lives for the grace period', function (): void {
    [$service, , $clock] = tokenService();
    $old = $service->create(7, 'Next.js', [TokenScope::Read], ['/wp/v2'], 86400);

    $new = $service->rotate(7, $old->token->id, 600);

    expect($new->token->id)->not->toBe($old->token->id)
        ->and($new->token->name)->toBe('Next.js')
        ->and($new->token->routes)->toBe(['/wp/v2'])
        ->and($new->token->expiresAt)->toBe(10000 + 86400)
        ->and($service->authenticate($old->secret))->not->toBeNull();

    $clock->now += 600;

    expect($service->authenticate($old->secret))->toBeNull()
        ->and($service->authenticate($new->secret))->not->toBeNull();
});

it('rotates a token that never expires into one that never expires', function (): void {
    [$service] = tokenService();
    $old = $service->create(7, 'a', [TokenScope::Write]);

    expect($service->rotate(7, $old->token->id, 60)->token->expiresAt)->toBeNull();
});

it('uses the real clock and the system random bytes by default', function (): void {
    $service = new TokenService(new InMemoryTokenStore);
    $issued = $service->create(1, 'a', [TokenScope::Read], [], 60);

    expect($issued->token->createdAt)->toBeGreaterThan(1_700_000_000)
        ->and($service->authenticate($issued->secret))->not->toBeNull();
});
