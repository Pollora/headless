<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use GraphQL\Error\UserError;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Cors\CorsRequest;
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Domain\Token\ScopePolicy;
use Pollora\Headless\Domain\Token\Token;

/**
 * Makes WPGraphQL safe for a public headless site. Inert while WPGraphQL is not active.
 *
 * - Its settings are imposed in code (`graphql_get_setting_section_field_value`), not written to the database.
 * - Its `Access-Control-Allow-Origin: *` is replaced by the CORS policy of the package.
 * - An API token (`Authorization: Bearer hl.…`) may use it, inside its scopes: a token without the write
 *   scope cannot run a mutation, and one limited to routes needs `/graphql` among them.
 *
 * A preview session (`hlp.…`) is not accepted here on purpose: it is made for one post and WPGraphQL would
 * run the request as the editor, with all of their rights. A server-side client previews with an API token and
 * the `X-GraphQL-Preview` header, which WPGraphQL handles itself.
 */
final class GraphQl implements Module
{
    private const array ROOT_TYPES = ['RootQuery' => 'GET', 'RootMutation' => 'POST'];

    public function __construct(
        private readonly GraphQlPolicy $policy,
        private readonly ?CorsPolicy $cors = null,
        private readonly ?TokenAuthentication $tokens = null,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('graphql_get_setting_section_field_value', [$this, 'setting'], 20, 5);

        if ($this->cors instanceof CorsPolicy) {
            $hooks->addFilter('graphql_response_headers_to_send', [$this, 'headers'], 20);
        }

        if ($this->tokens instanceof TokenAuthentication) {
            $hooks->addFilter('graphql_pre_resolve_field', [$this, 'guard'], 5, 6);
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function setting(mixed $value, mixed $default, string $option, array $fields, string $section): mixed
    {
        return $this->policy->resolve($section, $option, $value);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        if (! $this->cors instanceof CorsPolicy) {
            return $headers;
        }

        $decision = $this->cors->decide(new CorsRequest(
            self::server('HTTP_ORIGIN'),
            self::server('REQUEST_METHOD') ?? 'POST',
            self::server('HTTP_ACCESS_CONTROL_REQUEST_METHOD'),
            self::server('HTTP_ACCESS_CONTROL_REQUEST_HEADERS'),
        ));

        $vary = array_filter(array_map(trim(...), explode(',', ($headers['Vary'] ?? '').','.($decision->headers['Vary'] ?? ''))), static fn (string $name): bool => $name !== '');

        unset($headers['Access-Control-Allow-Origin'], $headers['Access-Control-Allow-Headers'], $headers['Access-Control-Max-Age']);

        $headers = [...$headers, ...$decision->headers];
        $headers['Vary'] = implode(', ', array_values(array_unique($vary)));

        return $headers;
    }

    /**
     * Stops a root field a token is not allowed to run: the pre-resolve filter runs before any resolver.
     *
     * @param  mixed  $result  what an earlier filter resolved, null when nothing did
     */
    public function guard(mixed $result, mixed $source, mixed $args, mixed $context, mixed $info, string $typeName = ''): mixed
    {
        $token = $this->tokens?->token();

        if (! $token instanceof Token || ! isset(self::ROOT_TYPES[$typeName])) {
            return $result;
        }

        if (! ScopePolicy::allows($token, self::ROOT_TYPES[$typeName], '/graphql')) {
            throw new UserError('This API token is not allowed to make this request.');
        }

        return $result;
    }

    private static function server(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
