<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * A computed field of a REST answer that costs something (the blocks of a post, its featured image in full):
 * WordPress computes every registered field when a request does not limit them, so these fields are computed only
 * when the request asks for them by name in `_fields`.
 */
final class OptInField
{
    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public static function requested(\WP_REST_Request $request, string $field): bool
    {
        $fields = $request->get_param('_fields');
        $fields = is_array($fields) ? $fields : explode(',', (string) $fields);

        return in_array($field, array_map(trim(...), array_map(strval(...), $fields)), true);
    }
}
