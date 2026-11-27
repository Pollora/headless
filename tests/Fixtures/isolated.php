<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use RuntimeException;

/**
 * Runs PHP in a process of its own, for what a constant or an `exit` decides: neither can be undone in the
 * process of the suite. These lines are checked, but not counted by the coverage.
 *
 * @return string what the code printed
 */
function isolated(string $code): string
{
    $process = proc_open(
        [PHP_BINARY, '-r', 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; '.$code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if ($process === false) {
        throw new RuntimeException('No process could be started.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);

    return proc_close($process) === 0 && $errors === '' ? $output : throw new RuntimeException($errors.$output);
}
