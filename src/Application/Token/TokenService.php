<?php

declare(strict_types=1);

namespace Pollora\Headless\Application\Token;

use Closure;
use Pollora\Headless\Domain\Exceptions\TokenNotFoundException;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Domain\Token\TokenSecret;
use Pollora\Headless\Domain\Token\TokenStore;

/**
 * Creates, checks, rotates and revokes API tokens.
 *
 * A token acts as the user it was made for, limited to its scopes and routes: it can never do more than
 * the user, and an administrator can lower it further.
 */
final readonly class TokenService
{
    /** How long a token's last-use date goes without being rewritten: it is a write on a request that only reads. */
    private const int TOUCH_INTERVAL = 3600;

    private Closure $clock;

    private Closure $random;

    /**
     * @param  (callable(): int)|null  $clock  the current time; replaced in tests
     * @param  (callable(int): string)|null  $random  random bytes; replaced in tests
     */
    public function __construct(
        private TokenStore $store,
        ?callable $clock = null,
        ?callable $random = null,
    ) {
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : time(...);
        $this->random = $random !== null ? Closure::fromCallable($random) : random_bytes(...);
    }

    /**
     * Makes a token. The result carries the secret, which cannot be read again.
     *
     * @param  list<TokenScope>  $scopes
     * @param  list<string>  $routes
     * @param  int|null  $lifetime  seconds before it expires; null means never
     */
    public function create(int $userId, string $name, array $scopes, array $routes = [], ?int $lifetime = null): IssuedToken
    {
        $secret = TokenSecret::create($userId, bin2hex(($this->random)(6)), ($this->random)(32));
        $now = ($this->clock)();

        $token = new Token($secret->id, $userId, $name, $secret->hash(), $scopes, $routes, $now, $lifetime === null ? null : $now + $lifetime);
        $this->store->save($token);

        return new IssuedToken($token, $secret->toString());
    }

    /**
     * The token a client's secret stands for, or null when it is unknown, wrong or expired.
     */
    public function authenticate(string $presented): ?Token
    {
        $secret = TokenSecret::parse($presented);

        if (! $secret instanceof TokenSecret) {
            return null;
        }

        $token = $this->store->find($secret->userId, $secret->id);

        if (! $token instanceof Token || ! $secret->matches($token->hash) || $token->isExpired(($this->clock)())) {
            return null;
        }

        return $this->touched($token);
    }

    /**
     * @return list<Token>
     */
    public function list(int $userId): array
    {
        return $this->store->forUser($userId);
    }

    /**
     * @throws TokenNotFoundException
     */
    public function revoke(int $userId, string $id): void
    {
        $this->owned($userId, $id);
        $this->store->delete($userId, $id);
    }

    /**
     * Issues a new token like the old one and lets the old one run for a grace period, so a client can switch.
     *
     * @throws TokenNotFoundException
     */
    public function rotate(int $userId, string $id, int $grace): IssuedToken
    {
        $old = $this->owned($userId, $id);
        $issued = $this->create($userId, $old->name, $old->scopes, $old->routes, $old->expiresAt === null ? null : max(0, $old->expiresAt - ($this->clock)()));

        $this->store->save($old->expiringAt(($this->clock)() + $grace));

        return $issued;
    }

    private function touched(Token $token): Token
    {
        $now = ($this->clock)();

        if ($token->lastUsedAt !== null && $now - $token->lastUsedAt < self::TOUCH_INTERVAL) {
            return $token;
        }

        $token = $token->usedAt($now);
        $this->store->save($token);

        return $token;
    }

    private function owned(int $userId, string $id): Token
    {
        return $this->store->find($userId, $id) ?? throw TokenNotFoundException::for($id);
    }
}
