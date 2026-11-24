<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * What the public front of WordPress does once the site is headless.
 */
enum FrontMode: string
{
    /** Leave the front as WordPress serves it. */
    case Off = 'off';

    /** Send the visitor to the same path on the front-end origin. */
    case Redirect = 'redirect';

    /** Answer with a short holding page. */
    case Page = 'page';

    /** Answer with a JSON body and a status code. */
    case Json = 'json';
}
