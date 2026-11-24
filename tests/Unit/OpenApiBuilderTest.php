<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Schema\OpenApiBuilder;

/**
 * @return array<string, list<array<string, mixed>>>
 */
function wordPressRoutes(): array
{
    return [
        '/' => [['methods' => ['GET' => true], 'args' => ['context' => ['type' => 'string']]]],
        '/wp/v2/posts' => [
            ['methods' => ['GET' => true], 'args' => ['per_page' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100, 'description' => 'How many.'], 'status' => ['type' => ['string', 'null'], 'enum' => ['publish', 'draft']]]],
            ['methods' => ['POST' => true], 'args' => ['title' => ['type' => 'string', 'required' => true, 'description' => 'The title.'], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
        ],
        '/wp/v2/posts/(?P<id>[\d]+)' => [
            ['methods' => ['GET' => true, 'HEAD' => true], 'args' => ['id' => ['type' => 'integer', 'description' => 'Unique identifier.'], 'context' => ['type' => 'string', 'enum' => ['view', 'edit']]]],
            ['methods' => ['PUT' => true, 'PATCH' => true], 'args' => ['id' => ['type' => 'integer']]],
            ['methods' => ['DELETE' => true], 'args' => ['force' => ['type' => 'boolean', 'default' => false]]],
        ],
        '/headless/v1/site' => [['methods' => 'GET', 'args' => []]],
        '/oembed/1.0/embed' => [['methods' => ['GET' => true], 'args' => ['url' => ['type' => 'string', 'format' => 'uri', 'required' => true]]]],
    ];
}

it('turns a route pattern into an OpenAPI path', function (): void {
    expect(OpenApiBuilder::path('/wp/v2/posts/(?P<id>[\d]+)'))->toBe('/wp/v2/posts/{id}')
        ->and(OpenApiBuilder::path('/wp/v2/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)'))->toBe('/wp/v2/{parent}/revisions/{id}')
        ->and(OpenApiBuilder::path('/headless/v1/site'))->toBe('/headless/v1/site')
        ->and(OpenApiBuilder::path('/x/(?P<slug>(?:a|b)+)'))->toBe('/x/{slug}');
});

it('builds a document with every route but the index', function (): void {
    $document = (new OpenApiBuilder('The site', '1.0', 'https://cms.example.com/wp-json/'))->build(wordPressRoutes());

    expect($document['openapi'])->toBe('3.0.3')
        ->and($document['info'])->toBe(['title' => 'The site', 'version' => '1.0'])
        ->and($document['servers'])->toBe([['url' => 'https://cms.example.com/wp-json']])
        ->and(array_keys($document['paths']))->toBe(['/headless/v1/site', '/oembed/1.0/embed', '/wp/v2/posts', '/wp/v2/posts/{id}'])
        ->and($document['components']['securitySchemes']['bearer']['scheme'])->toBe('bearer');
});

it('keeps only the namespaces asked for', function (): void {
    $document = (new OpenApiBuilder('t', '1', 'https://x.test', ['wp/v2', '/headless/v1/']))->build(wordPressRoutes());

    expect(array_keys($document['paths']))->toBe(['/headless/v1/site', '/wp/v2/posts', '/wp/v2/posts/{id}']);
});

it('describes the query of a read and the body of a write', function (): void {
    $paths = (new OpenApiBuilder('t', '1', 'https://x.test'))->build(wordPressRoutes())['paths'];

    $list = $paths['/wp/v2/posts']['get'];
    $create = $paths['/wp/v2/posts']['post'];

    expect($list['operationId'])->toBe('getWpV2Posts')
        ->and($list['tags'])->toBe(['wp/v2'])
        ->and($list['parameters'][0])->toBe(['name' => 'per_page', 'in' => 'query', 'description' => 'How many.', 'schema' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100]])
        ->and($list['parameters'][1]['schema'])->toBe(['type' => 'string', 'enum' => ['publish', 'draft']])
        ->and($create)->not->toHaveKey('parameters')
        ->and($create['requestBody']['content']['application/json']['schema'])->toBe([
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string', 'description' => 'The title.'], 'categories' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            'required' => ['title'],
        ]);
});

it('makes the path parameters required, one operation per method, and skips HEAD', function (): void {
    $item = (new OpenApiBuilder('t', '1', 'https://x.test'))->build(wordPressRoutes())['paths']['/wp/v2/posts/{id}'];

    expect(array_keys($item))->toBe(['get', 'put', 'patch', 'delete'])
        ->and($item['get']['parameters'][0])->toBe(['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']])
        ->and($item['get']['parameters'][1]['name'])->toBe('context')
        ->and($item['delete']['parameters'][1] ?? $item['delete']['parameters'][0])->toHaveKey('schema')
        ->and($item['put']['parameters'])->toHaveCount(1);
});

it('reads methods given as a string, and tolerates an odd endpoint', function (): void {
    $document = (new OpenApiBuilder('t', '1', 'https://x.test'))->build([
        '/a/v1/x' => [['methods' => 'GET, POST', 'args' => 'nope'], ['methods' => 12, 'args' => ['skipped' => 'not an array', 7 => ['type' => 'string']]]],
    ]);

    expect(array_keys($document['paths']['/a/v1/x']))->toBe(['get', 'post']);
});
