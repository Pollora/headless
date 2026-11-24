<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Preview;

/**
 * What a preview token is for.
 */
enum PreviewTokenType: string
{
    /** In the preview link: short, usable once, exchanged for a session. */
    case Link = 'link';

    /** Held by the front end: longer, bound to one post, read-only. */
    case Session = 'session';
}
