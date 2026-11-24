<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a preview token is past its expiry.
 */
final class PreviewTokenExpiredException extends RuntimeException
{
    public static function raise(): self
    {
        return new self('The preview token has expired.');
    }
}
