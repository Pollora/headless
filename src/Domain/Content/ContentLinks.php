<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Content;

use Pollora\Headless\Domain\Front\FrontUrls;

/**
 * Moves the links of a post's HTML to the front end.
 *
 * Editors paste the address of another page of the site into the content, and WordPress stores it as typed:
 * the WordPress host. A front end that renders that HTML would send its readers to the back end. Only the
 * `href` of a link is rewritten, and only when the page is one of the site's: a file (uploads, theme, plugin),
 * the API and the administration stay on the WordPress host, as do images and `srcset`.
 */
final readonly class ContentLinks
{
    /**
     * @param  list<string>  $keepPrefixes  paths that stay on the WordPress host, relative to the directory it is installed in
     */
    public function __construct(
        private FrontUrls $urls,
        private array $keepPrefixes = ['/wp-content', '/wp-includes', '/wp-json', '/wp-admin', '/wp-login.php', '/graphql'],
        private string $basePath = '',
    ) {}

    public function rewrite(string $html): string
    {
        if (! str_contains($html, 'href')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<a\b[^>]*?\shref=)(["\'])(.*?)\2#is',
            fn (array $match): string => $match[1].$match[2].$this->link($match[3]).$match[2],
            $html,
        );
    }

    private function link(string $href): string
    {
        $path = (string) parse_url($href, PHP_URL_PATH);

        foreach ($this->keepPrefixes as $prefix) {
            $kept = rtrim($this->basePath, '/').$prefix;

            if ($path === $kept || str_starts_with($path, $kept.'/')) {
                return $href;
            }
        }

        return $this->urls->toFront($href);
    }
}
