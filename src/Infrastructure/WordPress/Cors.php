<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Closure;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Cors\CorsRequest;

/**
 * Replaces WordPress's CORS answer on the REST API.
 *
 * Core reflects any origin and allows credentials. This module takes its handler off
 * `rest_pre_serve_request` (once core has added it, on `rest_api_init`) and answers
 * from the policy: the listed origins only, and `Vary: Origin` always.
 */
final class Cors implements Module
{
    private readonly Closure $send;

    /**
     * @param  (callable(string, bool): void)|null  $send  sends one header line, replacing an earlier one or not; replaced in tests
     */
    public function __construct(
        private readonly CorsPolicy $policy,
        ?callable $send = null,
    ) {
        $this->send = $send !== null ? Closure::fromCallable($send) : static function (string $line, bool $replace): void {
            header($line, $replace);
        };
    }

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'takeOverFromCore'], 20);
    }

    public function takeOverFromCore(): void
    {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', [$this, 'send'], 10, 3);
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function send(bool $served, mixed $result, \WP_REST_Request $request): bool
    {
        $decision = $this->policy->decide(new CorsRequest(
            self::server('HTTP_ORIGIN'),
            $request->get_method(),
            self::server('HTTP_ACCESS_CONTROL_REQUEST_METHOD'),
            self::server('HTTP_ACCESS_CONTROL_REQUEST_HEADERS'),
        ));

        foreach ($decision->headers as $name => $value) {
            ($this->send)($name.': '.$value, $name !== 'Vary');
        }

        return $served;
    }

    private static function server(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
