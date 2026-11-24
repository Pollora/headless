<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

use Pollora\Headless\Domain\Exceptions\InvalidTokenException;

/**
 * "30m", "12h", "30d", "2w": a number of seconds, for a token's lifetime or a rotation's grace period.
 */
final class Duration
{
    private const array UNITS = ['m' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

    /**
     * @throws InvalidTokenException
     */
    public static function seconds(string $value): int
    {
        if (preg_match('/\A(\d{1,6})([mhdw])\z/', trim($value), $match) !== 1 || (int) $match[1] === 0) {
            throw InvalidTokenException::unknownDuration($value);
        }

        return (int) $match[1] * self::UNITS[$match[2]];
    }
}
