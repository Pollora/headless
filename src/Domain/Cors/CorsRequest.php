<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Cors;

/**
 * What the CORS policy reads from a request: the Origin header, the method, and what a preflight asks for.
 */
final readonly class CorsRequest
{
    public function __construct(
        public ?string $origin,
        public string $method = 'GET',
        public ?string $requestMethod = null,
        public ?string $requestHeaders = null,
    ) {}

    /** A preflight is an OPTIONS request that names the method the real request will use. */
    public function isPreflight(): bool
    {
        return strtoupper($this->method) === 'OPTIONS' && $this->requestMethod !== null && $this->requestMethod !== '';
    }
}
