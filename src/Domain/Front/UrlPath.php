<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * The path of an address, with its query when it has one: `https://www.example.com/a/?b=1` gives `/a/?b=1`.
 */
final class UrlPath
{
    public static function of(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        return ((string) parse_url($url, PHP_URL_PATH) ?: '/').(is_string($query) ? '?'.$query : '');
    }
}
