<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

use Pollora\Headless\Domain\Preview\PostAccess;

/**
 * Posts and editing rights in memory, for the unit tests.
 */
final class FakePostAccess implements PostAccess
{
    /**
     * @param  list<int>  $posts  posts that exist and may be previewed
     * @param  array<int, list<int>>  $editors  user ID to the posts they can edit
     */
    public function __construct(public array $posts = [42], public array $editors = [7 => [42]]) {}

    public function isPreviewable(int $postId): bool
    {
        return in_array($postId, $this->posts, true);
    }

    public function canEdit(int $userId, int $postId): bool
    {
        return in_array($postId, $this->editors[$userId] ?? [], true);
    }
}
