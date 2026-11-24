<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

/**
 * An API token as it is stored: never the secret, only its hash.
 */
final readonly class Token
{
    /**
     * @param  list<TokenScope>  $scopes
     * @param  list<string>  $routes  REST route prefixes the token may reach; none means every route
     */
    public function __construct(
        public string $id,
        public int $userId,
        public string $name,
        public string $hash,
        public array $scopes,
        public array $routes,
        public int $createdAt,
        public ?int $expiresAt = null,
        public ?int $lastUsedAt = null,
    ) {}

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function expiringAt(int $expiresAt): self
    {
        return new self($this->id, $this->userId, $this->name, $this->hash, $this->scopes, $this->routes, $this->createdAt, $expiresAt, $this->lastUsedAt);
    }

    public function usedAt(int $lastUsedAt): self
    {
        return new self($this->id, $this->userId, $this->name, $this->hash, $this->scopes, $this->routes, $this->createdAt, $this->expiresAt, $lastUsedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user' => $this->userId,
            'name' => $this->name,
            'hash' => $this->hash,
            'scopes' => array_map(static fn (TokenScope $scope): string => $scope->value, $this->scopes),
            'routes' => $this->routes,
            'created' => $this->createdAt,
            'expires' => $this->expiresAt,
            'used' => $this->lastUsedAt,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $scopes = [];

        foreach (is_array($data['scopes'] ?? null) ? $data['scopes'] : [] as $scope) {
            $scope = is_string($scope) ? TokenScope::tryFrom($scope) : null;

            if ($scope instanceof TokenScope) {
                $scopes[] = $scope;
            }
        }

        if (! is_string($data['id'] ?? null) || ! is_string($data['hash'] ?? null) || ! is_int($data['user'] ?? null) || $scopes === []) {
            return null;
        }

        $routes = array_values(array_filter(is_array($data['routes'] ?? null) ? $data['routes'] : [], is_string(...)));

        return new self(
            $data['id'],
            $data['user'],
            is_string($data['name'] ?? null) ? $data['name'] : '',
            $data['hash'],
            $scopes,
            $routes,
            is_int($data['created'] ?? null) ? $data['created'] : 0,
            is_int($data['expires'] ?? null) ? $data['expires'] : null,
            is_int($data['used'] ?? null) ? $data['used'] : null,
        );
    }
}
