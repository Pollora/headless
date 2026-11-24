<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Media;

/**
 * The part of an image an editor wants kept whatever the crop: `x` and `y`, fractions of the original from 0 to 1.
 */
final readonly class FocalPoint
{
    public function __construct(
        public float $x,
        public float $y,
    ) {}

    /** Reads what is stored (`['x' => 0.25, 'y' => 0.75]`); anything else is no point. */
    public static function tryFrom(mixed $stored): ?self
    {
        if (! is_array($stored) || ! is_numeric($stored['x'] ?? null) || ! is_numeric($stored['y'] ?? null)) {
            return null;
        }

        $x = (float) $stored['x'];
        $y = (float) $stored['y'];

        return $x >= 0.0 && $x <= 1.0 && $y >= 0.0 && $y <= 1.0 ? new self($x, $y) : null;
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
