<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\Transport;

/**
 * An in-memory stand-in for a revalidation port, for the unit tests.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{string, string, array<string, string>}> */
    public array $sent = [];

    /** @var list<DeliveryResult> */
    public array $answers = [];

    public function send(Target $target, string $body, array $headers): DeliveryResult
    {
        $this->sent[] = [$target->name, $body, $headers];

        return array_shift($this->answers) ?? new DeliveryResult(true, 200);
    }
}
