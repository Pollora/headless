<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when the user behind a preview token can no longer edit the post.
 */
final class PreviewForbiddenException extends RuntimeException
{
    public static function raise(): self
    {
        return new self('You are no longer allowed to preview this post.');
    }
}
