<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Status;

/**
 * How serious the result of a check is.
 */
enum CheckLevel: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Fail = 'fail';
}
