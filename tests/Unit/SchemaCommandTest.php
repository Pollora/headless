<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\SchemaCommand;

function schemaOutput(): Output
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $messages = [];

        public function line(string $message): void
        {
            $this->messages[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->messages[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->messages[] = 'error: '.$message;
        }
    };
}

/**
 * @param  list<array{string, string}>  $written
 */
function schemaCommand(Output $output, ?Closure $graphql = null, array &$written = [], bool $writable = true): SchemaCommand
{
    return new SchemaCommand(
        static fn (): array => ['/wp/v2/posts' => [['methods' => ['GET' => true], 'args' => []]], '/headless/v1/site' => [['methods' => ['GET' => true], 'args' => []]]],
        static fn (): array => ['title' => 'Site', 'version' => '6.9', 'server' => 'https://cms.example.com/wp-json/'],
        $graphql ?? static fn (): ?string => null,
        $output,
        function (string $file, string $content) use (&$written, $writable): bool {
            $written[] = [$file, $content];

            return $writable;
        },
    );
}

it('prints the OpenAPI document', function (): void {
    $output = schemaOutput();

    schemaCommand($output)->openapi();
    $document = json_decode(substr($output->messages[0], 6), true);

    expect($document['info']['title'])->toBe('Site')->and(array_keys($document['paths']))->toBe(['/headless/v1/site', '/wp/v2/posts']);
});

it('keeps the namespaces asked for and writes to a file', function (): void {
    $output = schemaOutput();
    $written = [];

    schemaCommand($output, null, $written)->openapi([], ['namespace' => 'wp/v2, ', 'file' => 'openapi.json']);

    expect($written[0][0])->toBe('openapi.json')
        ->and(array_keys(json_decode($written[0][1], true)['paths']))->toBe(['/wp/v2/posts'])
        ->and($output->messages)->toBe(['success: 1 paths written to openapi.json.']);
});

it('says when a file cannot be written', function (): void {
    $output = schemaOutput();
    $written = [];

    schemaCommand($output, null, $written, false)->openapi([], ['file' => '/nope/openapi.json']);

    expect($output->messages)->toBe(['error: /nope/openapi.json could not be written.']);
});

it('prints and writes the GraphQL schema', function (): void {
    $output = schemaOutput();
    $written = [];
    $command = schemaCommand($output, static fn (): string => 'type Query { a: String }', $written);

    $command->graphql();
    $command->graphql([], ['file' => 'schema.graphql']);

    expect($output->messages)->toBe(['line: type Query { a: String }', 'success: The GraphQL schema written to schema.graphql.'])
        ->and($written)->toBe([['schema.graphql', "type Query { a: String }\n"]]);
});

it('says when WPGraphQL is missing or fails', function (): void {
    $missing = schemaOutput();
    schemaCommand($missing)->graphql();

    $broken = schemaOutput();
    schemaCommand($broken, static function (): string {
        throw new RuntimeException('no schema');
    })->graphql();

    expect($missing->messages)->toBe(['error: WPGraphQL is not active.'])
        ->and($broken->messages)->toBe(['error: The GraphQL schema could not be printed: no schema']);
});
