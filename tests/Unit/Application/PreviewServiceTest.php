<?php

declare(strict_types=1);

use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Exceptions\PreviewForbiddenException;
use Pollora\Headless\Domain\Exceptions\PreviewNotFoundException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenExpiredException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenUsedException;
use Pollora\Headless\Domain\Preview\PreviewClaims;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;

/**
 * @return array{PreviewService, FakePreviewStore, FakePostAccess, object}
 */
function previewService(string $audience = 'https://www.example.com'): array
{
    $store = new FakePreviewStore;
    $posts = new FakePostAccess;
    $clock = new class
    {
        public int $now = 10000;
    };
    $counter = 0;
    $random = static function (int $length) use (&$counter): string {
        $counter++;

        return str_repeat(chr($counter), $length);
    };

    return [new PreviewService($store, $posts, 'key', $audience, 300, 1800, fn (): int => $clock->now, $random), $store, $posts, $clock];
}

it('makes a link token that can be used once, and remembers it for as long as it lives', function (): void {
    [$service, $store] = previewService();

    $token = $service->linkToken(7, 42, 1);
    $claims = TokenSigner::verify($token, 'key', 10000, PreviewTokenType::Link);

    expect($claims->userId)->toBe(7)->and($claims->postId)->toBe(42)->and($claims->siteId)->toBe(1)
        ->and($claims->expiresAt)->toBe(10300)
        ->and($store->remembered)->toBe([$claims->id => 300]);
});

it('trades a link token for a session bound to the same post', function (): void {
    [$service] = previewService();

    $session = $service->exchange($service->linkToken(7, 42, 1));

    expect($session->token)->toStartWith('hlp.')
        ->and($session->expiresIn)->toBe(1800)
        ->and($session->postId)->toBe(42)
        ->and($session->userId)->toBe(7)
        ->and($service->authorize($session->token, 42)->userId)->toBe(7);
});

it('refuses to trade a link token twice', function (): void {
    [$service] = previewService();
    $token = $service->linkToken(7, 42, 1);

    $service->exchange($token);
    $service->exchange($token);
})->throws(PreviewTokenUsedException::class);

it('refuses a link token that expired, was forged, or is a session', function (): void {
    [$service, , , $clock] = previewService();
    $token = $service->linkToken(7, 42, 1);
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;

    expect(fn () => $service->exchange('forged.token'))->toThrow(PreviewTokenInvalidException::class)
        ->and(fn () => $service->exchange(substr($session, 4)))->toThrow(PreviewTokenInvalidException::class);

    $clock->now += 300;

    expect(fn () => $service->exchange($token))->toThrow(PreviewTokenExpiredException::class);
});

it('refuses a token made for another front end', function (): void {
    [$mine] = previewService('https://www.example.com');
    [$other] = previewService('https://other.example.org');

    $other->exchange($mine->linkToken(7, 42, 1));
})->throws(PreviewTokenInvalidException::class);

it('refuses to trade when the user can no longer edit the post, or the post is gone, and spends the token', function (): void {
    [$service, $store, $posts] = previewService();
    $revoked = $service->linkToken(7, 42, 1);
    $posts->editors = [];

    expect(fn () => $service->exchange($revoked))->toThrow(PreviewForbiddenException::class)
        ->and($store->remembered)->toBe([]);

    $posts->editors = [7 => [42]];
    $posts->posts = [];

    expect(fn () => $service->exchange($service->linkToken(7, 42, 1)))->toThrow(PreviewNotFoundException::class);
});

it('keeps a session to its post and to a user who can still edit it', function (): void {
    [$service, , $posts] = previewService();
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;

    expect(fn () => $service->authorize($session, 43))->toThrow(PreviewForbiddenException::class);

    $posts->editors = [];

    expect(fn () => $service->authorize($session, 42))->toThrow(PreviewForbiddenException::class);
});

it('refuses a session that expired, a link used as a session and a token without the session prefix', function (): void {
    [$service, , , $clock] = previewService();
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;
    $link = $service->linkToken(7, 42, 1);

    expect(fn () => $service->authorize('hlp.'.$link, 42))->toThrow(PreviewTokenInvalidException::class)
        ->and(fn () => $service->authorize($link, 42))->toThrow(PreviewTokenInvalidException::class);

    $clock->now += 1800;

    expect(fn () => $service->authorize($session, 42))->toThrow(PreviewTokenExpiredException::class);
});

it('refuses a token signed for another audience even with the right key', function (): void {
    [$service] = previewService();
    $claims = new PreviewClaims(PreviewTokenType::Session, 7, 42, 1, 'https://evil.example.org', time() + 600, 'x');

    $service->authorize('hlp.'.TokenSigner::sign($claims, 'key'), 42);
})->throws(PreviewTokenInvalidException::class);

it('uses the real clock and random bytes by default', function (): void {
    $service = new PreviewService(new FakePreviewStore, new FakePostAccess, 'key', 'https://www.example.com');

    expect($service->exchange($service->linkToken(7, 42, 1))->postId)->toBe(42);
});
