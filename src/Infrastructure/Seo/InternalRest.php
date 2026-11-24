<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

/**
 * Reads what a SEO plugin adds to the REST API, from inside WordPress: the plugin documents its output there,
 * and a REST route is a contract a plugin keeps, where its internal classes are not.
 */
final class InternalRest
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<mixed>|null null when the route answers an error or something that is not an object
     */
    public static function get(string $route, array $params = []): ?array
    {
        $request = new \WP_REST_Request('GET', $route);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);
        $data = $response->get_data();

        return ! $response->is_error() && is_array($data) ? $data : null;
    }

    /**
     * One field of the REST answer for a post or a term.
     */
    public static function field(string $route, string $field): mixed
    {
        return self::get($route, ['_fields' => $field])[$field] ?? null;
    }
}
