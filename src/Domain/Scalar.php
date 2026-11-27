<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain;

use Stringable;

/**
 * Reads a value that comes from outside (an option, a database row, a request, a hook) as text or as a number.
 *
 * Scalars read as a cast would read them. Anything else (an array, an object that is not text) is not a value:
 * it reads as the default, never as "Array" or 1.
 */
final class Scalar
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }
}
