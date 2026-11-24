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

    /**
     * Builds the target from a request URI such as "/blog/hello?x=1".
     *
     * @param  string  $basePath  the directory WordPress is installed in ("/wp" for https://example.com/wp): the target is relative to it
     */
    public static function fromUri(string $uri, string $method = 'GET', string $basePath = ''): self
    {
        $parts = explode('?', $uri, 2);
        $path = '/'.ltrim($parts[0], '/');
        $basePath = '/'.trim($basePath, '/');

        if ($basePath !== '/' && ($path === $basePath || str_starts_with($path, $basePath.'/'))) {
            $path = substr($path, strlen($basePath));
        }

        return new self($path, $parts[1] ?? '', strtoupper($method));
    }

    /** The path, with the query string when there is one. */
    public function pathAndQuery(bool $withQuery = true): string
    {
        return $this->path.($withQuery && $this->query !== '' ? '?'.$this->query : '');
    }
}
