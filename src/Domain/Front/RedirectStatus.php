<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * The status of a redirect to the front end. A temporary one is the default: browsers keep a 301 for a long time.
 */
enum RedirectStatus: int
{
    case Found = 302;
    case TemporaryRedirect = 307;
    case MovedPermanently = 301;
    case PermanentRedirect = 308;
}
