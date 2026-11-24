<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Token;

/**
 * Whether a token may make a request: the method it is allowed, and the routes it is limited to.
 */
final class ScopePolicy
{
    public static function allows(Token $token, string $method, string $route): bool
    {
        $safe = in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);

        if (! in_array(TokenScope::Write, $token->scopes, true) && ! ($safe && in_array(TokenScope::Read, $token->scopes, true))) {
            return false;
        }

        if ($token->routes === []) {
            return true;
        }

        foreach ($token->routes as $prefix) {
            $prefix = '/'.trim($prefix, '/');

            if ($route === $prefix || str_starts_with($route, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
