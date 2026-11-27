<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Schema\OpenApiBuilder;

/*
 * The OpenAPI document a client generator reads, part by part.
 */

/** @return array<string, array<string, mixed>> */
function documentPaths(array $routes, array $namespaces = []): array
{
    return (new OpenApiBuilder('t', '1', 'https://x.test', $namespaces))->build($routes)['paths'];
}

it('writes the whole document of a small API', function (): void {
    $document = (new OpenApiBuilder('The site', '2.0', 'https://x.test/wp-json/'))->build([
        '/a/v1/thing-list' => [['methods' => ['GET' => true], 'args' => []]],
    ]);

    expect($document)->toBe([
        'openapi' => '3.0.3',
        'info' => ['title' => 'The site', 'version' => '2.0'],
        'servers' => [['url' => 'https://x.test/wp-json']],
        'paths' => ['/a/v1/thing-list' => ['get' => ['operationId' => 'getAV1ThingList', 'tags' => ['a/v1'], 'responses' => ['200' => ['description' => 'OK']]]]],
        'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API token of the site (hl.…), or an application password with Basic authentication.']]],
    ]);
});

it('puts the arguments of a read and of a delete in the address, and those of a write in the body', function (string $method, bool $inAddress): void {
    $operation = documentPaths(['/a' => [['methods' => $method, 'args' => ['q' => ['type' => 'string']]]]])['/a'][strtolower($method)];

    expect(array_key_exists('parameters', $operation))->toBe($inAddress)
        ->and(array_key_exists('requestBody', $operation))->toBe(! $inAddress);
})->with([['GET', true], ['DELETE', true], ['POST', false], ['PUT', false], ['PATCH', false]]);

it('marks as required only what is required, in the address and in the body', function (): void {
    $args = ['a' => ['required' => true], 'b' => ['required' => false], 'c' => [], 'd' => ['required' => 1]];
    $paths = documentPaths(['/x' => [['methods' => 'GET, POST', 'args' => $args]], '/y' => [['methods' => 'POST', 'args' => ['b' => ['required' => false]]]]]);

    expect($paths['/x']['get']['parameters'])->toBe([
        ['name' => 'a', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ['name' => 'b', 'in' => 'query', 'schema' => ['type' => 'string']],
        ['name' => 'c', 'in' => 'query', 'schema' => ['type' => 'string']],
        ['name' => 'd', 'in' => 'query', 'schema' => ['type' => 'string']],
    ])->and($paths['/x']['post']['requestBody']['content']['application/json']['schema']['required'])->toBe(['a'])
        ->and($paths['/y']['post']['requestBody'])->toBe(['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]]]);
});

it('keeps each type OpenAPI knows, and calls the rest text', function (mixed $type, string $expected): void {
    $parameter = documentPaths(['/a' => [['methods' => 'GET', 'args' => ['q' => ['type' => $type]]]]])['/a']['get']['parameters'][0];

    expect($parameter['schema']['type'])->toBe($expected);
})->with([
    ['string', 'string'], ['integer', 'integer'], ['number', 'number'], ['boolean', 'boolean'], ['array', 'array'], ['object', 'object'],
    ['mixed', 'string'], ['null', 'string'], [5, 'string'], [['null', 'integer'], 'integer'], [['boolean', 'null'], 'boolean'], [['null'], 'string'], [[], 'string'],
]);

it('keeps the limits WordPress states, and no empty one', function (): void {
    $limits = ['enum' => ['a'], 'default' => 'a', 'minimum' => 1, 'maximum' => 2, 'format' => 'uri', 'minLength' => 3, 'maxLength' => 4];
    $args = ['q' => ['type' => 'string', 'sanitize_callback' => 'x', ...$limits], 'r' => ['default' => null], 'l' => ['type' => 'array'], 'm' => ['type' => 'array', 'items' => 'broken']];
    $parameters = documentPaths(['/a' => [['methods' => 'GET', 'args' => $args]]])['/a']['get']['parameters'];

    expect(array_column($parameters, 'schema'))->toBe([
        ['type' => 'string', ...$limits],
        ['type' => 'string'],
        ['type' => 'array', 'items' => ['type' => 'string']],
        ['type' => 'array', 'items' => ['type' => 'string']],
    ]);
});

it('reads every endpoint after one it cannot read, and a pattern that is a number', function (): void {
    $paths = documentPaths([
        '/broken' => 'not endpoints',
        '/a' => ['junk', ['methods' => 'get']],
        123 => [['methods' => 'GET']],
    ]);

    expect(array_keys($paths))->toBe(['/123', '/a']);
});

it('takes the methods a list switches on, written in any case', function (): void {
    $paths = documentPaths(['/a' => [['methods' => ['GET' => true, 'POST' => false]]], '/b' => [['methods' => ' post ,delete, OPTIONS']]]);

    expect(array_keys($paths['/a']))->toBe(['get'])->and(array_keys($paths['/b']))->toBe(['post', 'delete']);
});

it('names a group used twice in a pattern once', function (): void {
    $operation = documentPaths(['/a/(?P<id>\d+)|/b/(?P<id>\d+)' => [['methods' => 'GET']]]);

    expect(array_values($operation)[0]['get']['parameters'])->toHaveCount(1);
});

it('keeps a namespace itself, and nothing that only starts like it', function (): void {
    $routes = ['/wp/v2' => [['methods' => 'GET']], '/wp/v2x' => [['methods' => 'GET']], '/wp/v2/posts' => [['methods' => 'GET']]];

    expect(array_keys(documentPaths($routes, ['wp/v2'])))->toBe(['/wp/v2', '/wp/v2/posts']);
});

it('leaves out an argument that has no name or no description of itself', function (): void {
    $operation = documentPaths(['/a' => [['methods' => 'GET', 'args' => [5 => ['type' => 'string'], 'broken' => 'text', 'kept' => []]]]])['/a']['get'];

    expect(array_column($operation['parameters'], 'name'))->toBe(['kept']);
});
