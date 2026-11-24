<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a preview token is malformed, forged or made for something else.
 */
final class PreviewTokenInvalidException extends RuntimeException
{
    public static function raise(): self
    {
        return new self('The preview token is not valid.');
    }
}
