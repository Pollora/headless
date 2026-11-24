<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * Turns a URL of the WordPress site into the URL of the same page on the front end.
 *
 * The language is the one of the page, when a multilingual plugin tells it: a site can serve
 * each language from its own front-end origin.
 */
interface FrontUrls
{
    public function toFront(string $url, ?string $language = null): string;
}
