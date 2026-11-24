<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Preview\PostAccess;

/**
 * What the preview asks WordPress: does the post exist, may this user edit it.
 */
final class WordPressPostAccess implements PostAccess
{
    public function isPreviewable(int $postId): bool
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post || $post->post_status === 'trash' || $post->post_type === 'revision' || $post->post_type === 'auto-draft') {
            return false;
        }

        $type = get_post_type_object($post->post_type);

        return $type instanceof \WP_Post_Type && $type->show_in_rest;
    }

    public function canEdit(int $userId, int $postId): bool
    {
        return user_can($userId, 'edit_post', $postId);
    }
}
