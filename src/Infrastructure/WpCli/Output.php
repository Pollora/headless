<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

/**
 * What a command says to the person who ran it: WP-CLI's output in WordPress, a fake in tests.
 */
interface Output
{
    public function line(string $message): void;

    public function success(string $message): void;

    public function warning(string $message): void;

    public function error(string $message): void;
}
