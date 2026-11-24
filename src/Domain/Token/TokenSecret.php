<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

/**
 * The secret a client sends: `hl.<user>.<id>.<secret>`.
 *
 * The user and the id let the server find the stored hash without scanning; the secret is 256 random
 * bits, so a fast hash (SHA-256) is enough to store it. It is shown once, when the token is created.
 */
final readonly class TokenSecret
{
    private const string FORMAT = '/\Ahl\.(\d+)\.([0-9a-f]{12})\.([A-Za-z0-9_-]{43})\z/';

    private function __construct(
        public int $userId,
        public string $id,
        public string $secret,
    ) {}

    /**
     * @param  string  $randomId  12 hex characters, from bin2hex(random_bytes(6))
     * @param  string  $randomSecret  32 random bytes
     */
    public static function create(int $userId, string $randomId, string $randomSecret): self
    {
        return new self($userId, $randomId, rtrim(strtr(base64_encode($randomSecret), '+/', '-_'), '='));
    }

    /** Reads what a client sent; null when it is not shaped like a token. */
    public static function parse(string $value): ?self
    {
        if (preg_match(self::FORMAT, $value, $match) !== 1) {
            return null;
        }

        return new self((int) $match[1], $match[2], $match[3]);
    }

    public function toString(): string
    {
        return 'hl.'.$this->userId.'.'.$this->id.'.'.$this->secret;
    }

    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    /** Whether this secret is the one the stored hash was made from. */
    public function matches(string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash());
    }
}
