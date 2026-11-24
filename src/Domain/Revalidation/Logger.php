<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Where a failure that nobody is waiting for is said.
 */
interface Logger
{
    public function warning(string $message): void;
}
