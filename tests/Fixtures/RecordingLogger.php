<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Revalidation\Logger;

/**
 * An in-memory stand-in for a revalidation port, for the unit tests.
 */
final class RecordingLogger implements Logger
{
    /** @var list<string> */
    public array $warnings = [];

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
