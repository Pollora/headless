<?php

declare(strict_types=1);

namespace Pollora\Headless\Application\Preview;

/**
 * A session a front end holds to read the preview of one post.
 */
final readonly class PreviewSession
{
    public function __construct(
        public string $token,
        public int $expiresIn,
        public int $postId,
        public int $userId,
    ) {}
}
