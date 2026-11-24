<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a setting of the package has a value it does not understand.
 */
final class InvalidSettingsException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function forValue(string $key, string $value, array $allowed): self
    {
        return new self(sprintf('"%s" is not a valid value for "%s" (expected one of: %s).', $value, $key, implode(', ', $allowed)));
    }

    public static function missing(string $key): self
    {
        return new self(sprintf('"%s" is required and is empty.', $key));
    }
}
