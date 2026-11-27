<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * The paths the front gate never touches: the administration, the login, the APIs, the cron, the uploads.
 *
 * A path matches an entry when it is equal to it or lies below it, on a segment boundary:
 * "/wp-json" matches "/wp-json/wp/v2/posts" and not "/wp-jsonx".
 */
final readonly class AllowList
{
    /** @var list<string> */
    public array $prefixes;

    /**
     * @param  list<string>  $prefixes
     */
    public function __construct(array $prefixes)
    {
        $normalised = [];

        foreach ($prefixes as $prefix) {
            $prefix = '/'.trim($prefix, '/');
            $normalised[$prefix] = $prefix;
        }

        $this->prefixes = array_values($normalised);
    }

    /** What a headless site always needs to reach: it works on a classic install, on Bedrock and on Pollora. */
    public static function defaults(): self
    {
        return new self([
            '/wp-admin',
            '/wp-login.php',
            '/wp-json',
            '/graphql',
            '/wp-cron.php',
            '/wp-content/uploads',
            '/xmlrpc.php',
            '/wp-sitemap.xml',
            '/robots.txt',
        ]);
    }

    public function with(string ...$prefixes): self
    {
        return new self([...$this->prefixes, ...array_values($prefixes)]);
    }

    public function allows(RequestTarget $target): bool
    {
        $path = rtrim($target->path, '/');

        foreach ($this->prefixes as $prefix) {
            if ($prefix === '/') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path.'/', $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
