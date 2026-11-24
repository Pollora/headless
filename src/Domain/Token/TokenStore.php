<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

/**
 * Where a user's tokens are kept.
 */
interface TokenStore
{
    public function find(int $userId, string $id): ?Token;

    /**
     * @return list<Token>
     */
    public function forUser(int $userId): array;

    public function save(Token $token): void;

    public function delete(int $userId, string $id): void;
}
