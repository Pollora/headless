<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Preview;

/**
 * What a preview token says: who may preview which post, on which site, until when.
 */
final readonly class PreviewClaims
{
    public function __construct(
        public PreviewTokenType $type,
        public int $userId,
        public int $postId,
        public int $siteId,
        public string $audience,
        public int $expiresAt,
        public string $id,
    ) {}
}
