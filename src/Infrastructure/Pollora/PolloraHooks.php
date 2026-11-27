<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Pollora;

use Pollora\Headless\Infrastructure\Exceptions\HookServiceException;
use Pollora\Headless\Infrastructure\WordPress\HookRegistrar;

/**
 * The hooks of a Pollora project: they go through Pollora's hook service, which waits for WordPress to be loaded.
 *
 * The service is typed by duck: this package does not depend on pollora/hook.
 */
final readonly class PolloraHooks implements HookRegistrar
{
    public function __construct(
        private object $action,
        private object $filter,
    ) {}

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        self::add($this->action, $hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        self::add($this->filter, $hook, $callback, $priority, $acceptedArgs);
    }

    private static function add(object $service, string $hook, callable $callback, int $priority, int $acceptedArgs): void
    {
        $add = [$service, 'add'];

        if (! is_callable($add)) {
            throw HookServiceException::cannotAdd($service);
        }

        $add($hook, $callback, $priority, $acceptedArgs);
    }
}
