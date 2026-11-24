<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Domain\Revalidation\Logger;

/**
 * Says a failure in PHP's error log, where a site's other warnings are.
 */
final class ErrorLogLogger implements Logger
{
    public function warning(string $message): void
    {
        error_log('[pollora/headless] '.$message);
    }
}
