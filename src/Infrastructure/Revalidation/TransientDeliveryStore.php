<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Domain\Revalidation\DeliveryStore;

/**
 * Keeps the body of a failed notification as a transient until its retries are done.
 */
final class TransientDeliveryStore implements DeliveryStore
{
    private const string PREFIX = 'headless_dl_';

    public function save(string $id, string $body, int $seconds): void
    {
        set_transient(self::PREFIX.md5($id), $body, $seconds);
    }

    public function load(string $id): ?string
    {
        $body = get_transient(self::PREFIX.md5($id));

        return is_string($body) ? $body : null;
    }

    public function forget(string $id): void
    {
        delete_transient(self::PREFIX.md5($id));
    }
}
