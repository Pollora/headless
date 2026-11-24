<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Closure;
use Pollora\Headless\Domain\Front\FrontGatePolicy;
use Pollora\Headless\Domain\Front\GateDecision;
use Pollora\Headless\Domain\Front\RequestTarget;

/**
 * Carries out the front gate's decision on `template_redirect`.
 *
 * The priority is 11, after `redirect_canonical` (10), so a request WordPress would
 * redirect itself is not answered twice. The administration, the login, the REST
 * API and the cron never reach this hook, and the allow-list covers the rest.
 */
final class TemplateRedirectGate implements Module
{
    public const int PRIORITY = 11;

    private readonly Closure $terminate;

    /**
     * @param  (callable(): void)|null  $terminate  ends the request once the answer is sent; replaced in tests
     */
    public function __construct(
        private readonly FrontGatePolicy $policy,
        ?callable $terminate = null,
    ) {
        $this->terminate = $terminate !== null ? Closure::fromCallable($terminate) : static function (): never {
            exit;
        };
    }

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('template_redirect', [$this, 'handle'], self::PRIORITY);
    }

    public function handle(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

        $this->apply($this->policy->decide(RequestTarget::fromUri($uri, $method)));
    }

    private function apply(GateDecision $decision): void
    {
        if ($decision->isAllowed()) {
            return;
        }

        if ($decision->action === GateDecision::REDIRECT && $decision->location !== null) {
            wp_redirect($decision->location, $decision->status, 'Pollora Headless');
            ($this->terminate)();

            return;
        }

        status_header($decision->status);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        if ($decision->action === GateDecision::JSON) {
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode(['status' => $decision->status, 'message' => 'This site has no public front end.']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>'.esc_html(get_bloginfo('name')).'</title><p>This site has no public front end.</p>';
        }

        ($this->terminate)();
    }
}
