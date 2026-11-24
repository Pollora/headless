<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenStore;

/**
 * A token store in memory, for the unit tests.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, Token> */
    public array $tokens = [];

    public int $writes = 0;

    public function find(int $userId, string $id): ?Token
    {
        $token = $this->tokens[$userId.'/'.$id] ?? null;

        return $token;
    }

    public function forUser(int $userId): array
    {
        return array_values(array_filter($this->tokens, static fn (Token $token): bool => $token->userId === $userId));
    }

    public function save(Token $token): void
    {
        $this->writes++;
        $this->tokens[$token->userId.'/'.$token->id] = $token;
    }

    public function delete(int $userId, string $id): void
    {
        unset($this->tokens[$userId.'/'.$id]);
    }
}
