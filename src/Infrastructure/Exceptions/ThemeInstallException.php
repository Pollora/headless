<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Exceptions;

use RuntimeException;

/**
 * Raised when the empty theme cannot be copied into the themes directory.
 */
final class ThemeInstallException extends RuntimeException
{
    public static function notWritable(string $path): self
    {
        return new self(sprintf('Cannot write "%s": check the permissions of the themes directory.', $path));
    }
}
