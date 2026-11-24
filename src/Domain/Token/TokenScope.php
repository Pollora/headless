<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

use Pollora\Headless\Domain\Exceptions\InvalidTokenException;

/**
 * What a token may do. Writing includes reading.
 */
enum TokenScope: string
{
    /** Safe requests: GET, HEAD and OPTIONS. */
    case Read = 'read';

    /** Every request, the unsafe ones (POST, PUT, PATCH, DELETE) included. */
    case Write = 'write';

    /**
     * @throws InvalidTokenException
     */
    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name))) ?? throw InvalidTokenException::unknownScope($name);
    }
}
