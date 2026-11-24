<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Media;

/**
 * Moves the address of an uploaded file from the WordPress host to a CDN (or an image proxy) that serves the
 * `uploads` directory: `https://cms.example.com/wp-content/uploads/a.jpg` becomes `https://cdn.example.com/a.jpg`
 * when the CDN's root is the uploads directory, or `https://cdn.example.com/uploads/a.jpg` when it mirrors the site.
 *
 * Only the start of an address is replaced, whatever its scheme: a file anywhere else is left alone.
 */
final readonly class MediaUrls
{
    private string $uploads;

    private string $cdn;

    /**
     * @param  string  $uploadsBase  the uploads directory's address, such as https://cms.example.com/wp-content/uploads
     * @param  string  $cdnBase  where the same files are served, such as https://cdn.example.com
     */
    public function __construct(string $uploadsBase, string $cdnBase)
    {
        $this->uploads = rtrim((string) preg_replace('#\Ahttps?:#i', '', $uploadsBase), '/');
        $this->cdn = rtrim($cdnBase, '/');
    }

    public function toCdn(string $url): string
    {
        $bare = (string) preg_replace('#\Ahttps?:#i', '', $url);

        if ($this->uploads === '' || ($bare !== $this->uploads && ! str_starts_with($bare, $this->uploads.'/'))) {
            return $url;
        }

        return $this->cdn.substr($bare, strlen($this->uploads));
    }

    /** Every address of an uploaded file in a piece of HTML (an image, a link, a source set). */
    public function rewriteHtml(string $html): string
    {
        if ($this->uploads === '' || ! str_contains($html, ltrim($this->uploads, '/'))) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#https?:'.preg_quote($this->uploads, '#').'(?=[/"\'\s,?]|\z)#i',
            fn (): string => $this->cdn,
            $html,
        );
    }
}
