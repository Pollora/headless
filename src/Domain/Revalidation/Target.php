<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Where a notification goes: a name, an address, and the secret that signs what is sent.
 */
final readonly class Target
{
    public function __construct(
        public string $name,
        public string $url,
        public string $secret,
    ) {}
}
