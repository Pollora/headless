<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Status;

/**
 * The result of one check of `wp headless doctor`: what was looked at, how it went, and what to do about it.
 */
final readonly class Check
{
    public function __construct(
        public string $id,
        public CheckLevel $level,
        public string $message,
        public string $fix = '',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'level' => $this->level->value, 'message' => $this->message, 'fix' => $this->fix];
    }
}
