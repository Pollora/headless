<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Media;

/**
 * Where the files of the media library are read in their normalised shape.
 */
interface MediaCatalog
{
    /**
     * @return array<string, mixed>|null null when there is no such file, or the visitor may not see it
     */
    public function for(int $id): ?array;
}
