<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * One piece of the package (the front gate, the clean-up…): it attaches itself to WordPress and nothing else.
 */
interface Module
{
    public function register(HookRegistrar $hooks): void;
}
