<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Redirect;

/**
 * The redirects an editor manages: stored by the package, read and written one rule at a time.
 */
interface RedirectStore extends RedirectSource
{
    /** Adds a rule, or replaces the one for the same source path. */
    public function save(Redirect $redirect): void;

    /** Removes the rule for a path; returns whether there was one. */
    public function remove(string $from): bool;
}
