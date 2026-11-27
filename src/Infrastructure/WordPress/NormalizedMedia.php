<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Media\FocalPoint;
use Pollora\Headless\Domain\Media\MediaCatalog;
use Pollora\Headless\Domain\Media\MediaKind;
use Pollora\Headless\Domain\Scalar;

/**
 * A file of the media library in one shape that does not depend on its kind: what a front end needs to show it,
 * with the same keys for an image, a video, a sound and a document.
 *
 * Addresses are the ones WordPress gives (`wp_get_attachment_url()`), so a CDN set with MediaCdn is already in them.
 * The focal point is the one the editor set with pollora/media (the `_pollora_focal_point` meta, and its
 * `pollora/media/focal_point` filter), when it is there.
 */
final class NormalizedMedia implements MediaCatalog
{
    /** The post meta pollora/media keeps the focal point in: `{x, y}`, fractions of the original. */
    public const string FOCAL_META = '_pollora_focal_point';

    public function for(int $id): ?array
    {
        $post = get_post($id);

        if (! $post instanceof \WP_Post || $post->post_type !== 'attachment' || ! $this->isVisible($post)) {
            return null;
        }

        $mime = (string) get_post_mime_type($post);
        $kind = MediaKind::fromMime($mime);
        $url = (string) wp_get_attachment_url($id);
        $metadata = wp_get_attachment_metadata($id);
        $metadata = is_array($metadata) ? $metadata : [];

        $isImage = $kind === MediaKind::Image;

        return [
            'id' => $id,
            'kind' => $kind->value,
            'mime' => $mime,
            'url' => $url,
            'title' => $post->post_title,
            'alt' => Scalar::string(get_post_meta($id, '_wp_attachment_image_alt', true)),
            'caption' => $post->post_excerpt,
            'description' => $post->post_content,
            'width' => $isImage ? self::number($metadata['width'] ?? null) : null,
            'height' => $isImage ? self::number($metadata['height'] ?? null) : null,
            'filesize' => self::number($metadata['filesize'] ?? null),
            'duration' => $isImage ? null : self::number($metadata['length'] ?? null),
            'focal_point' => $isImage ? $this->focalPoint($id) : null,
            'sizes' => $isImage ? $this->sizes($url, $metadata) : [],
            'srcset' => $isImage ? (wp_get_attachment_image_srcset($id, 'full') ?: null) : null,
            'modified_gmt' => str_replace(' ', 'T', $post->post_modified_gmt),
        ];
    }

    /**
     * Who may see a file: anyone for a file of the library, and for a file attached to a post, whoever may see that post.
     * A private file, or one in the media trash, is for who may read it.
     * (`read_post` is no use here: it refuses an attachment's own "inherit" status to a visitor.)
     */
    private function isVisible(\WP_Post $file): bool
    {
        if ($file->post_status === 'private' || $file->post_status === 'trash') {
            return current_user_can('read_post', $file->ID);
        }

        $parent = $file->post_parent > 0 ? get_post($file->post_parent) : null;

        return ! $parent instanceof \WP_Post || is_post_publicly_viewable($parent) || current_user_can('read_post', $parent->ID);
    }

    /**
     * @return array{x: float, y: float}|null
     */
    private function focalPoint(int $id): ?array
    {
        $stored = FocalPoint::tryFrom(get_post_meta($id, self::FOCAL_META, true));
        $filtered = apply_filters('pollora/media/focal_point', $stored?->toArray(), $id);

        return (FocalPoint::tryFrom($filtered) ?? $stored)?->toArray();
    }

    /**
     * @param  array<mixed>  $metadata
     * @return array<string, array{url: string, width: int, height: int}>
     */
    private function sizes(string $url, array $metadata): array
    {
        $directory = substr($url, 0, (int) strrpos($url, '/'));
        $sizes = [];

        foreach (is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [] as $name => $size) {
            if (is_array($size) && is_string($size['file'] ?? null)) {
                $sizes[(string) $name] = ['url' => $directory.'/'.$size['file'], 'width' => Scalar::int($size['width'] ?? 0), 'height' => Scalar::int($size['height'] ?? 0)];
            }
        }

        ksort($sizes);

        $width = self::number($metadata['width'] ?? null);
        $height = self::number($metadata['height'] ?? null);

        if ($width !== null && $height !== null) {
            $sizes['full'] = ['url' => $url, 'width' => $width, 'height' => $height];
        }

        return $sizes;
    }

    /** A measure of the file's metadata; null when it holds none. */
    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
