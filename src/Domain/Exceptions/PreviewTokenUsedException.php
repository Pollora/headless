<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a one-time preview token is presented a second time.
 */
final class PreviewTokenUsedException extends RuntimeException
{
    public static function raise(): self
    {
        return new self('The preview token has already been used.');
    }
}
