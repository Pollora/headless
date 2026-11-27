<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WpCli;

use Closure;
use Pollora\Headless\Domain\Schema\OpenApiBuilder;
use Throwable;

/**
 * Write the schema of the API to a file, for the tool that generates a client from it.
 */
final readonly class SchemaCommand
{
    /**
     * @param  Closure(): array<mixed>  $routes  the REST routes of the site
     * @param  Closure(): array{title: string, version: string, server: string}  $site  what the OpenAPI document says of the site
     * @param  Closure(): mixed  $graphql  the GraphQL schema as SDL, or null when WPGraphQL is not active
     * @param  Closure(string, string): bool  $write  saves a file; replaced in tests
     */
    public function __construct(
        private Closure $routes,
        private Closure $site,
        private Closure $graphql,
        private Output $output,
        private Closure $write,
    ) {}

    /**
     * The REST API as an OpenAPI 3.0 document (JSON).
     *
     * ## OPTIONS
     *
     * [--namespace=<namespaces>]
     * : Comma-separated REST namespaces to keep, such as wp/v2,headless/v1. Every namespace by default.
     *
     * [--file=<path>]
     * : Write to this file instead of printing.
     *
     * ## EXAMPLES
     *
     *     wp headless schema openapi --namespace=wp/v2,headless/v1 --file=openapi.json
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function openapi(array $args = [], array $assoc = []): void
    {
        $namespace = $assoc['namespace'] ?? '';

        if (! is_scalar($namespace)) {
            $this->output->error('--namespace takes a comma-separated list.');

            return;
        }

        $namespaces = array_values(array_filter(array_map(trim(...), explode(',', (string) $namespace)), static fn (string $one): bool => $one !== ''));
        $site = ($this->site)();

        $document = (new OpenApiBuilder($site['title'], $site['version'], $site['server'], $namespaces))->build(($this->routes)());

        $this->emit(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", $assoc, sprintf('%d paths', count($document['paths'])));
    }

    /**
     * The GraphQL schema as SDL. Needs WPGraphQL.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Write to this file instead of printing.
     *
     * ## EXAMPLES
     *
     *     wp headless schema graphql --file=schema.graphql
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function graphql(array $args = [], array $assoc = []): void
    {
        try {
            $schema = ($this->graphql)();
        } catch (Throwable $throwable) {
            $this->output->error('The GraphQL schema could not be printed: '.$throwable->getMessage());

            return;
        }

        if ($schema === null) {
            $this->output->error('WPGraphQL is not active.');

            return;
        }

        if (! is_string($schema)) {
            $this->output->error('The GraphQL schema could not be printed: WPGraphQL gave no text.');

            return;
        }

        $this->emit($schema."\n", $assoc, 'the GraphQL schema');
    }

    /**
     * @param  array<string, mixed>  $assoc
     */
    private function emit(string $content, array $assoc, string $what): void
    {
        $file = $assoc['file'] ?? null;

        if (! is_string($file) || $file === '') {
            $this->output->line(rtrim($content));

            return;
        }

        if (! ($this->write)($file, $content)) {
            $this->output->error(sprintf('%s could not be written.', $file));

            return;
        }

        $this->output->success(sprintf('%s written to %s.', ucfirst($what), $file));
    }
}
