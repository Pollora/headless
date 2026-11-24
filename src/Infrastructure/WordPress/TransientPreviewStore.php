<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Preview\PreviewStore;

/**
 * Remembers the one-time preview tokens as transients: in the object cache when there is a persistent one, in the options otherwise.
 */
final class TransientPreviewStore implements PreviewStore
{
    private const string PREFIX = 'headless_pv_';

    public function remember(string $id, int $seconds): void
    {
        set_transient(self::PREFIX.$id, 1, $seconds);
    }

    public function burn(string $id): bool
    {
        if (get_transient(self::PREFIX.$id) === false) {
            return false;
        }

        // The deletion decides: of two requests that both saw the token, one deletes it
        return delete_transient(self::PREFIX.$id);
    }
}
