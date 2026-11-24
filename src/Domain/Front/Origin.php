<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

use Pollora\Headless\Domain\Exceptions\InvalidOriginException;

/**
 * The scheme, host and optional port of a site (https://www.example.com), without path.
 */
final readonly class Origin
{
    private function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
    ) {}

    /**
     * @throws InvalidOriginException when the value is not an absolute http(s) URL
     */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw InvalidOriginException::for($url);
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw InvalidOriginException::for($url);
        }

        return new self($scheme, strtolower($parts['host']), $parts['port'] ?? null);
    }

    /** The origin as a string, without a trailing slash. */
    public function toString(): string
    {
        return $this->scheme.'://'.$this->host.($this->port === null ? '' : ':'.$this->port);
    }

    /** Whether both origins point to the same host and port, whatever the scheme. */
    public function sameHostAs(self $other): bool
    {
        return $this->host === $other->host && $this->effectivePort() === $other->effectivePort();
    }

    private function effectivePort(): int
    {
        return $this->port ?? ($this->scheme === 'https' ? 443 : 80);
    }
}
