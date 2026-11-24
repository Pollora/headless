<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Raised when the request to create a token asks for something that does not exist.
 */
final class InvalidTokenException extends InvalidArgumentException
{
    public static function unknownScope(string $scope): self
    {
        return new self(sprintf('"%s" is not a token scope (use read or write).', $scope));
    }

    public static function unknownDuration(string $value): self
    {
        return new self(sprintf('"%s" is not a duration (use a number and a unit: 30m, 12h, 30d, 2w).', $value));
    }
}
