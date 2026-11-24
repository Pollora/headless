<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Pollora\PolloraHooks;

it('hands actions and filters to the Pollora hook services', function (): void {
    $calls = new ArrayObject;
    $service = static fn (string $kind): object => new readonly class($kind, $calls)
    {
        public function __construct(private string $kind, private ArrayObject $calls) {}

        public function add(string $hook, callable $callback, int $priority, int $args): void
        {
            $this->calls->append([$this->kind, $hook, $priority, $args]);
        }
    };

    $hooks = new PolloraHooks($service('action'), $service('filter'));
    $hooks->addAction('init', 'strlen', 3);
    $hooks->addFilter('wp_headers', 'strlen', 10, 2);

    expect($calls->getArrayCopy())->toBe([['action', 'init', 3, 1], ['filter', 'wp_headers', 10, 2]]);
});
