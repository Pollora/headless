<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Redirect;

/**
 * Where redirects come from: the ones an editor wrote, the old addresses of renamed posts, a SEO plugin's manager.
 */
interface RedirectSource
{
    /**
     * @return list<Redirect>
     */
    public function redirects(): array;
}
