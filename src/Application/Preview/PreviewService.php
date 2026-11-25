<?php

declare(strict_types=1);

namespace Pollora\Headless\Application\Preview;

use Closure;
use Pollora\Headless\Domain\Exceptions\PreviewForbiddenException;
use Pollora\Headless\Domain\Exceptions\PreviewNotFoundException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenExpiredException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenUsedException;
use Pollora\Headless\Domain\Preview\PostAccess;
use Pollora\Headless\Domain\Preview\PreviewClaims;
use Pollora\Headless\Domain\Preview\PreviewStore;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;

/**
 * Previews a post from a JS front end, without a WordPress login there.
 *
 * 1. The editor's preview link carries a **link token**: signed, five minutes, usable once, for one user and one post.
 * 2. The front end exchanges it for a **session**: signed, thirty minutes, read-only, for the same post.
 * 3. Each read of the preview checks the session and that the user can still edit the post.
 *
 * A link token that leaks (a log, a Referer) is useless once the front end has used it, or after five minutes.
 */
final readonly class PreviewService
{
    private Closure $clock;

    private Closure $random;

    /**
     * @param  string  $key  the signing key; it never leaves the server
     * @param  string  $audience  the front-end origin the tokens are made for
     * @param  (callable(): int)|null  $clock  the current time; replaced in tests
     * @param  (callable(int): string)|null  $random  random bytes; replaced in tests
     */
    public function __construct(
        private PreviewStore $store,
        private PostAccess $posts,
        private string $key,
        private string $audience,
        private int $linkTtl = 300,
        private int $sessionTtl = 1800,
        ?callable $clock = null,
        ?callable $random = null,
    ) {
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : time(...);
        $this->random = $random !== null ? Closure::fromCallable($random) : random_bytes(...);
    }

    /** The token for a preview link: remembered, so that it can be used once. */
    public function linkToken(int $userId, int $postId, int $siteId): string
    {
        $claims = $this->claims(PreviewTokenType::Link, $userId, $postId, $siteId, $this->linkTtl);
        $this->store->remember($claims->id, $this->linkTtl);

        return TokenSigner::sign($claims, $this->key);
    }

    /**
     * Trades a link token for a session.
     *
     * @throws PreviewTokenInvalidException
     * @throws PreviewTokenExpiredException
     * @throws PreviewTokenUsedException
     * @throws PreviewForbiddenException when the user can no longer edit the post
     * @throws PreviewNotFoundException when the post is gone
     */
    public function exchange(string $linkToken, ?int $siteId = null): PreviewSession
    {
        $claims = TokenSigner::verify($linkToken, $this->key, ($this->clock)(), PreviewTokenType::Link);
        $this->checkAudience($claims);
        $this->checkSite($claims, $siteId);

        // Burn before anything else: a token that fails the checks below is spent too
        if (! $this->store->burn($claims->id)) {
            throw PreviewTokenUsedException::raise();
        }

        $this->checkPost($claims);

        $session = $this->claims(PreviewTokenType::Session, $claims->userId, $claims->postId, $claims->siteId, $this->sessionTtl);

        return new PreviewSession('hlp.'.TokenSigner::sign($session, $this->key), $this->sessionTtl, $claims->postId, $claims->userId);
    }

    /**
     * Checks a session for a post and returns its claims; the user must still be able to edit the post.
     *
     * @throws PreviewTokenInvalidException
     * @throws PreviewTokenExpiredException
     * @throws PreviewForbiddenException
     * @throws PreviewNotFoundException
     */
    public function authorize(string $sessionToken, int $postId, ?int $siteId = null): PreviewClaims
    {
        if (! str_starts_with($sessionToken, 'hlp.')) {
            throw PreviewTokenInvalidException::raise();
        }

        $claims = TokenSigner::verify(substr($sessionToken, 4), $this->key, ($this->clock)(), PreviewTokenType::Session);
        $this->checkAudience($claims);
        $this->checkSite($claims, $siteId);

        if ($claims->postId !== $postId) {
            throw PreviewForbiddenException::raise();
        }

        $this->checkPost($claims);

        return $claims;
    }

    private function claims(PreviewTokenType $type, int $userId, int $postId, int $siteId, int $ttl): PreviewClaims
    {
        return new PreviewClaims($type, $userId, $postId, $siteId, $this->audience, ($this->clock)() + $ttl, bin2hex(($this->random)(16)));
    }

    /** The keys come from the salts of the whole network: a token made on one site must not open a post of another. */
    private function checkSite(PreviewClaims $claims, ?int $siteId): void
    {
        if ($siteId !== null && $claims->siteId !== $siteId) {
            throw PreviewTokenInvalidException::raise();
        }
    }

    private function checkAudience(PreviewClaims $claims): void
    {
        if (! hash_equals($this->audience, $claims->audience)) {
            throw PreviewTokenInvalidException::raise();
        }
    }

    private function checkPost(PreviewClaims $claims): void
    {
        if (! $this->posts->isPreviewable($claims->postId)) {
            throw PreviewNotFoundException::raise();
        }

        if (! $this->posts->canEdit($claims->userId, $claims->postId)) {
            throw PreviewForbiddenException::raise();
        }
    }
}
