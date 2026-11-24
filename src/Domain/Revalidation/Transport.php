<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Sends a signed body to a target.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(Target $target, string $body, array $headers): DeliveryResult;
}
