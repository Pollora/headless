<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a redirect rule cannot be kept: an address that is not one, a status that is not a redirect, a pattern that does not compile.
 */
final class InvalidRedirectException extends InvalidArgumentException
{
    public static function badSource(string $from): self
    {
        return new self(sprintf('"%s" is not a path to redirect: it must start with "/" and hold no space.', $from));
    }

    public static function badTarget(string $to): self
    {
        return new self(sprintf('"%s" is not a destination: use a path starting with "/" or an absolute http(s) URL.', $to));
    }

    public static function badStatus(int $status): self
    {
        return new self(sprintf('%d is not a redirect status (use 301, 302, 307, 308, or 410 for a page that is gone).', $status));
    }

    public static function badPattern(string $from): self
    {
        return new self(sprintf('"%s" is not a valid pattern.', $from));
    }

    public static function loop(string $from): self
    {
        return new self(sprintf('"%s" redirects to itself.', $from));
    }
}
