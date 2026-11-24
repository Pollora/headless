<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Cache;

/**
 * The validator of a response body, and the comparison a conditional request needs.
 */
final class Etag
{
    /** The strong ETag of a body: its hash between quotes. */
    public static function forBody(string $body): string
    {
        return '"'.md5($body).'"';
    }

    /**
     * Whether the client already holds this version: `If-None-Match` lists it (weak or strong), or is `*`.
     */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
