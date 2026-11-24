<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

use Closure;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Exceptions\InvalidTokenException;
use Pollora\Headless\Domain\Exceptions\TokenNotFoundException;
use Pollora\Headless\Domain\Token\Duration;
use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenScope;

/**
 * Manage the API tokens of a user.
 *
 * A token acts as its user, limited to its scopes. It is shown once, when it is created: keep it.
 */
final readonly class TokenCommand
{
    /**
     * @param  Closure(string): ?int  $resolveUser  a user ID, login or email to an ID
     * @param  Closure(): int  $now
     */
    public function __construct(
        private TokenService $tokens,
        private Closure $resolveUser,
        private Closure $now,
        private Output $output,
    ) {}

    /**
     * Create a token.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * [--name=<name>]
     * : What the token is for.
     *
     * [--scopes=<scopes>]
     * : Comma-separated: read, write. Default: read.
     *
     * [--routes=<routes>]
     * : Comma-separated REST route prefixes the token is limited to, such as /wp/v2/posts. Default: every route.
     *
     * [--expires=<duration>]
     * : 30m, 12h, 30d, 2w. Default: never.
     *
     * ## EXAMPLES
     *
     *     wp headless token create editor --name="Next.js build" --scopes=read --expires=90d
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function create(array $args = [], array $assoc = []): void
    {
        $userId = $this->user($args[0] ?? '');

        if ($userId === null) {
            return;
        }

        try {
            $scopes = array_map(TokenScope::fromName(...), self::list((string) ($assoc['scopes'] ?? 'read')));
            $lifetime = isset($assoc['expires']) ? Duration::seconds((string) $assoc['expires']) : null;
        } catch (InvalidTokenException $invalidTokenException) {
            $this->output->error($invalidTokenException->getMessage());

            return;
        }

        $issued = $this->tokens->create($userId, (string) ($assoc['name'] ?? 'API token'), $scopes, self::list((string) ($assoc['routes'] ?? '')), $lifetime);

        $this->output->success('Token '.$issued->token->id.' created. Copy the secret now: it cannot be shown again.');
        $this->output->line($issued->secret);
    }

    /**
     * List the tokens of a user (never their secrets).
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function list_(array $args = [], array $assoc = []): void
    {
        $userId = $this->user($args[0] ?? '');

        if ($userId === null) {
            return;
        }

        $tokens = $this->tokens->list($userId);

        foreach ($tokens as $token) {
            $this->output->line($this->describe($token));
        }

        if ($tokens === []) {
            $this->output->line('No token.');
        }
    }

    /**
     * Revoke a token.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * <id>
     * : The token ID (see `list`).
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function revoke(array $args = [], array $assoc = []): void
    {
        $userId = $this->user($args[0] ?? '');

        if ($userId === null) {
            return;
        }

        try {
            $this->tokens->revoke($userId, $args[1] ?? '');
        } catch (TokenNotFoundException $tokenNotFoundException) {
            $this->output->error($tokenNotFoundException->getMessage());

            return;
        }

        $this->output->success('Token revoked.');
    }

    /**
     * Replace a token: a new one is issued, and the old one keeps working for a grace period.
     *
     * ## OPTIONS
     *
     * <user>
     * : User ID, login or email.
     *
     * <id>
     * : The token ID (see `list`).
     *
     * [--grace=<duration>]
     * : How long the old token still works: 30m, 12h, 2d. Default: 24h.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function rotate(array $args = [], array $assoc = []): void
    {
        $userId = $this->user($args[0] ?? '');

        if ($userId === null) {
            return;
        }

        try {
            $issued = $this->tokens->rotate($userId, $args[1] ?? '', Duration::seconds((string) ($assoc['grace'] ?? '24h')));
        } catch (TokenNotFoundException|InvalidTokenException $exception) {
            $this->output->error($exception->getMessage());

            return;
        }

        $this->output->success('Token '.$issued->token->id.' replaces '.($args[1] ?? '').', which works for the grace period. Copy the secret now: it cannot be shown again.');
        $this->output->line($issued->secret);
    }

    private function user(string $who): ?int
    {
        $userId = ($this->resolveUser)($who);

        if ($userId === null) {
            $this->output->error(sprintf('No user "%s".', $who));
        }

        return $userId;
    }

    private function describe(Token $token): string
    {
        $expires = $token->expiresAt === null ? 'never expires' : ($token->isExpired(($this->now)()) ? 'expired' : 'expires '.gmdate('Y-m-d H:i', $token->expiresAt).' UTC');

        return sprintf(
            '%s  %s  [%s]  %s  %s',
            $token->id,
            $token->name,
            implode(',', array_map(static fn (TokenScope $scope): string => $scope->value, $token->scopes)),
            $token->routes === [] ? 'all routes' : implode(',', $token->routes),
            $expires,
        );
    }

    /**
     * @return list<string>
     */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }
}
