<?php

declare(strict_types=1);

use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Exceptions\InvalidRedirectException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Front\RequestTarget;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

it('never lets a visitor\'s path turn a redirect into an address on another site', function (): void {
    $rule = new Redirect('/go/(.*)', '/$1', 301, true);

    expect($rule->resolve('/go//evil.com'))->toBeNull()
        ->and($rule->resolve('/go/\\evil.com'))->toBeNull()
        ->and($rule->resolve('/go/about'))->toBe('/about')
        ->and((new Redirect('/x/(.*)', 'https://example.org/$1', 301, true))->resolve('/x/a'))->toBe('https://example.org/a');
});

it('refuses a pattern that repeats a repetition', function (string $pattern): void {
    new Redirect($pattern, '/to', 301, true);
})->throws(InvalidRedirectException::class)->with(['/(a+)+', '/(.*)*x', '/(a*)*', '/(?:a+){2,}', '/(\d+)+/end']);

it('keeps the ordinary patterns', function (string $pattern): void {
    expect((new Redirect($pattern, '/to', 301, true))->regex)->toBeTrue();
})->with(['/blog/(.*)', '/news/(\d{4})/(.+)', '/a+b', '/(foo|bar)/baz', '/page/(\d+)/?']);

it('resolves the dots of an address before the gate sees it', function (string $uri, string $path): void {
    expect(RequestTarget::fromUri($uri)->path)->toBe($path);
})->with([
    ['/wp-admin/../?p=1', '/'],
    ['/wp-admin/%2e%2e/secret', '/secret'],
    ['/a/./b/../c', '/a/c'],
    ['/../../x', '/x'],
    ['/blog/', '/blog/'],
    ['/a/..', '/'],
]);

it('lets a rotated token live no longer than it already could', function (): void {
    $now = 1000;
    $store = new InMemoryTokenStore;
    $counter = 0;
    $service = new TokenService($store, static fn (): int => $now, static function (int $length) use (&$counter): string {
        $counter++;

        return str_repeat(chr($counter), $length);
    });
    $issued = $service->create(7, 'next', [TokenScope::Read], [], 60);

    $service->rotate(7, $issued->token->id, 7 * 86400);

    expect($store->find(7, $issued->token->id)?->expiresAt)->toBe(1060);
});

it('refuses a preview token made on another site', function (): void {
    $service = new PreviewService(
        new FakePreviewStore,
        new FakePostAccess,
        str_repeat('k', 40),
        'https://www.example.com',
        300,
        1800,
    );
    $link = $service->linkToken(7, 42, 2);

    $service->exchange($link, 1);
})->throws(PreviewTokenInvalidException::class);

it('wants a preview secret of 32 characters at least', function (): void {
    Settings::fromArray(['frontend_url' => 'https://www.example.com', 'preview' => ['url' => '/p', 'secret' => 'short']], 'https://cms.example.com');
})->throws(InvalidSettingsException::class, 'at least 32');
