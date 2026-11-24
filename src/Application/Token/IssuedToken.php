<?php

declare(strict_types=1);

namespace Pollora\Headless\Application\Token;

use Pollora\Headless\Domain\Token\Token;

/**
 * A token that has just been made, with the secret that is shown once.
 */
final readonly class IssuedToken
{
    public function __construct(
        public Token $token,
        public string $secret,
    ) {}
}
