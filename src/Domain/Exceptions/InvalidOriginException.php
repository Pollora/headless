<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a configured front-end or back-end URL is not an absolute http(s) URL.
 */
final class InvalidOriginException extends InvalidArgumentException
{
    public static function for(string $value): self
    {
        return new self(sprintf('"%s" is not an absolute http(s) URL (expected something like https://www.example.com).', $value));
    }
}
