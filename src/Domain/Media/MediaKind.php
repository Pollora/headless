<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Media;

/**
 * What a front end does with a file: shows it as an image, plays it, or links to it.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case File = 'file';

    public static function fromMime(string $mime): self
    {
        return match (strtolower(strstr($mime, '/', true) ?: '')) {
            'image' => self::Image,
            'video' => self::Video,
            'audio' => self::Audio,
            default => self::File,
        };
    }
}
