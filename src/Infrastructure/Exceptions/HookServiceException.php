<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Exceptions;

use RuntimeException;

/**
 * Raised when the hook service of the project cannot attach a hook.
 */
final class HookServiceException extends RuntimeException
{
    public static function notAnObject(): self
    {
        return new self('The container gives no hook service object.');
    }

    public static function cannotAdd(object $service): self
    {
        return new self(sprintf('The hook service "%s" has no add() method.', $service::class));
    }
}
