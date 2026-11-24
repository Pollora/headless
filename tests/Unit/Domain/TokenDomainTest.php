<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Exceptions\InvalidTokenException;
use Pollora\Headless\Domain\Token\Duration;
use Pollora\Headless\Domain\Token\ScopePolicy;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Domain\Token\TokenSecret;

function aToken(array $scopes = [TokenScope::Read], array $routes = [], ?int $expires = null): Token
{
    return new Token('0123456789ab', 7, 'Next.js', hash('sha256', 'secret'), $scopes, $routes, 1000, $expires);
}

it('builds a secret a client can send back, and reads it', function (): void {
    $secret = TokenSecret::create(7, '0123456789ab', str_repeat("\x01", 32));
    $parsed = TokenSecret::parse($secret->toString());

    expect($secret->toString())->toStartWith('hl.7.0123456789ab.')
        ->and($parsed)->not->toBeNull()
        ->and($parsed?->userId)->toBe(7)
        ->and($parsed?->id)->toBe('0123456789ab')
        ->and($parsed?->matches($secret->hash()))->toBeTrue()
        ->and($parsed?->matches(hash('sha256', 'another')))->toBeFalse();
});

it('does not read what is not shaped like a token', function (string $value): void {
    expect(TokenSecret::parse($value))->toBeNull();
})->with(['', 'hl.7.xx.yy', 'Bearer abc', 'hl.7.0123456789ab.short', 'hl..0123456789ab.'.'A'.str_repeat('b', 42), 'hl.7.0123456789AB.'.str_repeat('A', 43)]);

it('names the scopes and refuses an unknown one', function (): void {
    expect(TokenScope::fromName(' Read '))->toBe(TokenScope::Read)
        ->and(TokenScope::fromName('write'))->toBe(TokenScope::Write);

    TokenScope::fromName('admin');
})->throws(InvalidTokenException::class, 'not a token scope');

it('reads durations', function (string $value, int $seconds): void {
    expect(Duration::seconds($value))->toBe($seconds);
})->with([['30m', 1800], ['12h', 43200], ['30d', 2592000], ['2w', 1209600], [' 1d ', 86400]]);

it('refuses what is not a duration', function (string $value): void {
    Duration::seconds($value);
})->throws(InvalidTokenException::class, 'not a duration')->with(['', '0d', '10', 'd', '1y', '-5d', '1.5h']);

it('lets a read token make safe requests only', function (): void {
    expect(ScopePolicy::allows(aToken(), 'GET', '/wp/v2/posts'))->toBeTrue()
        ->and(ScopePolicy::allows(aToken(), 'head', '/wp/v2/posts'))->toBeTrue()
        ->and(ScopePolicy::allows(aToken(), 'POST', '/wp/v2/posts'))->toBeFalse()
        ->and(ScopePolicy::allows(aToken(), 'DELETE', '/wp/v2/posts/1'))->toBeFalse();
});

it('lets a write token make every request', function (): void {
    expect(ScopePolicy::allows(aToken([TokenScope::Write]), 'POST', '/wp/v2/posts'))->toBeTrue()
        ->and(ScopePolicy::allows(aToken([TokenScope::Write]), 'GET', '/wp/v2/posts'))->toBeTrue();
});

it('limits a token to its routes, on a segment boundary', function (): void {
    $token = aToken(routes: ['/wp/v2/posts', 'acme/v1/']);

    expect(ScopePolicy::allows($token, 'GET', '/wp/v2/posts'))->toBeTrue()
        ->and(ScopePolicy::allows($token, 'GET', '/wp/v2/posts/12'))->toBeTrue()
        ->and(ScopePolicy::allows($token, 'GET', '/acme/v1/things'))->toBeTrue()
        ->and(ScopePolicy::allows($token, 'GET', '/wp/v2/postsx'))->toBeFalse()
        ->and(ScopePolicy::allows($token, 'GET', '/wp/v2/users'))->toBeFalse();
});

it('expires at the given time, never without one', function (): void {
    expect(aToken(expires: 2000)->isExpired(1999))->toBeFalse()
        ->and(aToken(expires: 2000)->isExpired(2000))->toBeTrue()
        ->and(aToken()->isExpired(PHP_INT_MAX))->toBeFalse();
});

it('goes to an array and back, without the secret', function (): void {
    $token = aToken([TokenScope::Read, TokenScope::Write], ['/wp/v2'], 5000)->usedAt(1500);
    $again = Token::fromArray($token->toArray());

    expect($again)->toEqual($token)
        ->and($token->toArray())->not->toHaveKey('secret')
        ->and($token->expiringAt(9)->expiresAt)->toBe(9);
});

it('does not read a stored token that is incomplete', function (array $data): void {
    expect(Token::fromArray($data))->toBeNull();
})->with([
    'nothing' => [[]],
    'no scope' => [['id' => 'a', 'hash' => 'h', 'user' => 1, 'scopes' => ['nope']]],
    'no hash' => [['id' => 'a', 'user' => 1, 'scopes' => ['read']]],
    'user as text' => [['id' => 'a', 'hash' => 'h', 'user' => '1', 'scopes' => ['read']]],
]);

it('fills in what a stored token lacks', function (): void {
    $token = Token::fromArray(['id' => 'a', 'hash' => 'h', 'user' => 1, 'scopes' => ['read', 5], 'routes' => ['/x', 3]]);

    expect($token?->name)->toBe('')
        ->and($token?->routes)->toBe(['/x'])
        ->and($token?->createdAt)->toBe(0)
        ->and($token?->expiresAt)->toBeNull();
});
