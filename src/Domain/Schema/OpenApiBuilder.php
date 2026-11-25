<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Schema;

/**
 * An OpenAPI 3.0 document from the routes of the WordPress REST API.
 *
 * It reads the shape `rest_get_server()->get_routes()` returns, so that it needs no WordPress function:
 * each route is a pattern with named groups, and each of its endpoints lists its methods and its arguments.
 * The result is meant for a client generator, not for a reader: every answer is a plain `200`, since
 * WordPress does not describe what an endpoint returns outside of the schema of its resources.
 *
 * @phpstan-type Endpoint array{methods?: array<string, mixed>|string, args?: mixed}
 */
final readonly class OpenApiBuilder
{
    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  list<string>  $namespaces  the namespaces to keep, such as `wp/v2`; none keeps every one
     */
    public function __construct(
        private string $title,
        private string $version,
        private string $server,
        private array $namespaces = [],
    ) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $routes  the route patterns and their endpoints
     * @return array<string, mixed>
     */
    public function build(array $routes): array
    {
        $paths = [];

        foreach ($routes as $pattern => $endpoints) {
            $path = self::path($pattern);

            if (! $this->wanted($path)) {
                continue;
            }

            $names = self::pathParameters($pattern);

            foreach ($endpoints as $endpoint) {
                foreach (self::methods($endpoint['methods'] ?? []) as $method) {
                    $paths[$path][strtolower($method)] = $this->operation($method, $path, $names, self::args($endpoint['args'] ?? []));
                }
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $this->title, 'version' => $this->version],
            'servers' => [['url' => rtrim($this->server, '/')]],
            'paths' => $paths,
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API token of the site (hl.…), or an application password with Basic authentication.']]],
        ];
    }

    /**
     * `/wp/v2/posts/(?P<id>[\d]+)` becomes `/wp/v2/posts/{id}`.
     */
    public static function path(string $pattern): string
    {
        $path = preg_replace('/\(\?P<(\w+)>(?:[^()]|\([^()]*\))*\)/', '{$1}', $pattern) ?? $pattern;

        return '/'.trim($path, '/');
    }

    /**
     * @return list<string>
     */
    private static function pathParameters(string $pattern): array
    {
        preg_match_all('/\(\?P<(\w+)>/', $pattern, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function wanted(string $path): bool
    {
        if ($path === '/') {
            return false;
        }

        if ($this->namespaces === []) {
            return true;
        }

        foreach ($this->namespaces as $namespace) {
            $namespace = '/'.trim($namespace, '/');

            if ($path === $namespace || str_starts_with($path, $namespace.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function methods(mixed $methods): array
    {
        $names = is_array($methods) ? array_keys(array_filter($methods)) : explode(',', (string) $methods);
        $names = array_map(static fn (mixed $name): string => strtoupper(trim((string) $name)), $names);

        return array_values(array_intersect(self::METHODS, $names));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function args(mixed $args): array
    {
        if (! is_array($args)) {
            return [];
        }

        return array_filter($args, static fn (mixed $arg, mixed $name): bool => is_string($name) && is_array($arg), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  list<string>  $pathNames
     * @param  array<string, array<string, mixed>>  $args
     * @return array<string, mixed>
     */
    private function operation(string $method, string $path, array $pathNames, array $args): array
    {
        $parameters = [];
        $body = [];
        $required = [];

        foreach ($pathNames as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => self::schema($args[$name] ?? [])];
        }

        foreach ($args as $name => $arg) {
            if (in_array($name, $pathNames, true)) {
                continue;
            }

            if (in_array($method, ['GET', 'DELETE'], true)) {
                $parameters[] = array_filter([
                    'name' => $name,
                    'in' => 'query',
                    'required' => ($arg['required'] ?? false) === true ? true : null,
                    'description' => is_string($arg['description'] ?? null) ? $arg['description'] : null,
                    'schema' => self::schema($arg),
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            $body[$name] = self::schema($arg) + (is_string($arg['description'] ?? null) ? ['description' => $arg['description']] : []);

            if (($arg['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        $operation = [
            'operationId' => self::operationId($method, $path),
            'tags' => [self::tag($path)],
            'responses' => ['200' => ['description' => 'OK']],
        ];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($body !== []) {
            $operation['requestBody'] = ['content' => ['application/json' => ['schema' => array_filter(['type' => 'object', 'properties' => $body, 'required' => $required], static fn (mixed $value): bool => $value !== [])]]];
        }

        return $operation;
    }

    /**
     * The JSON schema of an argument: its type, and the limits WordPress states.
     *
     * @param  array<string, mixed>  $arg
     * @return array<string, mixed>
     */
    private static function schema(array $arg): array
    {
        $type = $arg['type'] ?? 'string';
        $type = is_array($type) ? (array_values(array_diff($type, ['null']))[0] ?? 'string') : $type;
        $type = is_string($type) && in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'], true) ? $type : 'string';

        $schema = ['type' => $type];

        if ($type === 'array') {
            $items = is_array($arg['items'] ?? null) ? $arg['items'] : [];
            $schema['items'] = self::schema($items);
        }

        foreach (['enum', 'default', 'minimum', 'maximum', 'format', 'minLength', 'maxLength'] as $key) {
            if (array_key_exists($key, $arg) && $arg[$key] !== null) {
                $schema[$key] = $arg[$key];
            }
        }

        return $schema;
    }

    private static function operationId(string $method, string $path): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return strtolower($method).implode('', array_map(ucfirst(...), $words));
    }

    private static function tag(string $path): string
    {
        $parts = explode('/', trim($path, '/'));

        return implode('/', array_slice($parts, 0, 2));
    }
}
