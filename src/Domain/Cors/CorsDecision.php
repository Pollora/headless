<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Cors;

/**
 * The headers the answer carries for a cross-origin request.
 */
final readonly class CorsDecision
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $headers,
        public bool $allowed,
    ) {}
}
