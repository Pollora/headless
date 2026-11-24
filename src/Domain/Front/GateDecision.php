<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * What the front gate decided for one request.
 */
final readonly class GateDecision
{
    public const string ALLOW = 'allow';

    public const string REDIRECT = 'redirect';

    public const string PAGE = 'page';

    public const string JSON = 'json';

    private function __construct(
        public string $action,
        public int $status = 200,
        public ?string $location = null,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function redirect(string $location, RedirectStatus $status): self
    {
        return new self(self::REDIRECT, $status->value, $location);
    }

    public static function page(int $status = 404): self
    {
        return new self(self::PAGE, $status);
    }

    public static function json(int $status = 404): self
    {
        return new self(self::JSON, $status);
    }

    public function isAllowed(): bool
    {
        return $this->action === self::ALLOW;
    }
}
