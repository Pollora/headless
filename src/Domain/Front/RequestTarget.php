<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * What the front gate needs to know about a request: the path, the query string and the method.
 */
final readonly class RequestTarget
{
    public string $path;

    public function __construct(
        string $path,
        public string $query = '',
        public string $method = 'GET',
    ) {
        $this->path = '/'.ltrim($path, '/');
    }

    /** Builds the target from a request URI such as "/blog/hello?x=1". */
    public static function fromUri(string $uri, string $method = 'GET'): self
    {
        $parts = explode('?', $uri, 2);

        return new self($parts[0], $parts[1] ?? '', strtoupper($method));
    }

    /** The path, with the query string when there is one. */
    public function pathAndQuery(bool $withQuery = true): string
    {
        return $this->path.($withQuery && $this->query !== '' ? '?'.$this->query : '');
    }
}
