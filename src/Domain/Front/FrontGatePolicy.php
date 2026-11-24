<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Front;

/**
 * Decides what happens to a request that reaches the public front of WordPress.
 *
 * The policy is pure: it reads a request and answers with a decision. A WordPress
 * hook, or a Laravel middleware on Pollora, carries the decision out.
 */
final readonly class FrontGatePolicy
{
    public function __construct(
        private FrontMode $mode,
        private AllowList $allowList,
        private Origin $backend,
        private ?Origin $front,
        private RedirectStatus $redirectStatus = RedirectStatus::Found,
        private bool $preserveQuery = true,
        private int $blockedStatus = 404,
    ) {}

    /** Whether the gate can ever stop a request: with the mode off there is nothing to hook. */
    public function isActive(): bool
    {
        return $this->mode !== FrontMode::Off;
    }

    public function decide(RequestTarget $target): GateDecision
    {
        if ($this->mode === FrontMode::Off || $this->allowList->allows($target)) {
            return GateDecision::allow();
        }

        return match ($this->mode) {
            FrontMode::Redirect => $this->redirect($target),
            FrontMode::Page => GateDecision::page($this->blockedStatus),
            default => GateDecision::json($this->blockedStatus),
        };
    }

    private function redirect(RequestTarget $target): GateDecision
    {
        // Without a front-end URL, or with the front on the host of WordPress, a redirect would send the visitor back here: loop
        if (! $this->front instanceof Origin || $this->front->sameHostAs($this->backend)) {
            return GateDecision::allow();
        }

        return GateDecision::redirect(
            $this->front->toString().$target->pathAndQuery($this->preserveQuery),
            $this->redirectStatus,
        );
    }
}
