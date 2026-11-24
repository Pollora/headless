<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Preview;

/**
 * What the preview needs to ask WordPress about a post and a user.
 */
interface PostAccess
{
    /** Whether the post exists and may be previewed (not trashed, a type with a REST route). */
    public function isPreviewable(int $postId): bool;

    /** Whether the user can edit the post: the same right the editor's Preview button needs. */
    public function canEdit(int $userId, int $postId): bool;
}
