<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when the post to preview does not exist, or cannot be previewed.
 */
final class PreviewNotFoundException extends RuntimeException
{
    public static function raise(): self
    {
        return new self('No such post to preview.');
    }
}
