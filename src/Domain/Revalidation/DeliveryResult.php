<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * What came back from a target: a 2xx is a success, anything else (or no answer) is worth trying again.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public bool $delivered,
        public int $status = 0,
        public ?string $error = null,
    ) {}
}
