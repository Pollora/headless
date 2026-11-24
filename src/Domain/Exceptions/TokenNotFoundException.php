<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a token the user names is not one of theirs.
 */
final class TokenNotFoundException extends RuntimeException
{
    public static function for(string $id): self
    {
        return new self(sprintf('No token "%s" for this user.', $id));
    }
}
