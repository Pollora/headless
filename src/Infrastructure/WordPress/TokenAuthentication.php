<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Closure;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Token\ScopePolicy;
use Pollora\Headless\Domain\Token\Token;

/**
 * Logs a REST request in from `Authorization: Bearer hl.…`, and keeps it inside the token's scopes.
 *
 * - `determine_current_user` (priority 20, after Application Passwords): the request runs as the token's user.
 * - `rest_authentication_errors`: a token that is wrong or expired answers 401, instead of silently
 *   turning the request into an anonymous one.
 * - `rest_request_before_callbacks`: a request outside the token's scopes or routes answers 403,
 *   before any handler runs.
 *
 * Some servers drop the Authorization header before PHP: with Apache, add
 * `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to the configuration (or .htaccess).
 */
final class TokenAuthentication implements Module
{
    private ?Token $token = null;

    private bool $rejected = false;

    private readonly Closure $isRestRequest;

    /**
     * @param  (callable(): bool)|null  $isRestRequest  whether this is a REST or a GraphQL request; replaced in tests
     */
    public function __construct(
        private readonly TokenService $tokens,
        ?callable $isRestRequest = null,
    ) {
        $this->isRestRequest = $isRestRequest !== null ? Closure::fromCallable($isRestRequest) : static fn (): bool => (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('is_graphql_http_request') && is_graphql_http_request());
    }

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('determine_current_user', [$this, 'authenticate'], 20);
        $hooks->addFilter('rest_authentication_errors', [$this, 'reject'], 90);
        $hooks->addFilter('rest_request_before_callbacks', [$this, 'enforceScopes'], 10, 3);
    }

    public function authenticate(mixed $userId): mixed
    {
        if ($userId || ! ($this->isRestRequest)()) {
            return $userId;
        }

        $presented = self::bearer();

        if ($presented === null || ! str_starts_with($presented, 'hl.')) {
            return $userId;
        }

        $token = $this->tokens->authenticate($presented);

        if (! $token instanceof Token) {
            $this->rejected = true;

            return $userId;
        }

        $this->token = $token;

        return $token->userId;
    }

    public function reject(mixed $errors): mixed
    {
        if ($errors !== null && $errors !== true) {
            return $errors;
        }

        // WordPress works the current user out lazily: until something asks for it, the token has not been looked at
        if (! $this->rejected && ! $this->token instanceof Token && ($this->isRestRequest)()) {
            wp_get_current_user();
        }

        return $this->rejected ? new \WP_Error('headless_token_invalid', 'The API token is invalid, revoked or expired.', ['status' => 401]) : $errors;
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function enforceScopes(mixed $response, mixed $handler, \WP_REST_Request $request): mixed
    {
        if (! $this->token instanceof Token || ScopePolicy::allows($this->token, $request->get_method(), $request->get_route())) {
            return $response;
        }

        return new \WP_Error('headless_token_scope', 'This API token is not allowed to make this request.', ['status' => 403]);
    }

    /** The token the request is made with, when it is one. */
    public function token(): ?Token
    {
        return $this->token;
    }

    private static function bearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (! is_string($header) || preg_match('/\ABearer\s+(\S+)\z/i', trim($header), $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
