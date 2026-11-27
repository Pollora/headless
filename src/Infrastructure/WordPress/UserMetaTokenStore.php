<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Token\Token;
use Pollora\Headless\Domain\Token\TokenStore;

/**
 * Keeps a user's tokens in one user meta (`_headless_tokens`): the hashes, never the secrets.
 *
 * The meta is protected (underscore), so it is neither listed in the REST API nor editable as a custom field.
 */
final class UserMetaTokenStore implements TokenStore
{
    public const string META_KEY = '_headless_tokens';

    public function find(int $userId, string $id): ?Token
    {
        $stored = $this->read($userId)[$id] ?? null;

        return is_array($stored) ? Token::fromArray($stored) : null;
    }

    public function forUser(int $userId): array
    {
        $tokens = [];

        foreach ($this->read($userId) as $stored) {
            $token = is_array($stored) ? Token::fromArray($stored) : null;

            if ($token instanceof Token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public function save(Token $token): void
    {
        $all = $this->read($token->userId);
        $all[$token->id] = $token->toArray();

        update_user_meta($token->userId, self::META_KEY, $all);
    }

    public function delete(int $userId, string $id): void
    {
        $all = $this->read($userId);
        unset($all[$id]);

        update_user_meta($userId, self::META_KEY, $all);
    }

    /**
     * @return array<mixed>
     */
    private function read(int $userId): array
    {
        $all = get_user_meta($userId, self::META_KEY, true);

        return is_array($all) ? $all : [];
    }
}
