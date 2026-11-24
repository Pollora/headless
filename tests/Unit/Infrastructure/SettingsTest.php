<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\RequestTarget;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;

const BACKEND = 'https://cms.example.com';

it('changes nothing without a front-end URL', function (): void {
    $settings = Settings::fromArray([], BACKEND);

    expect($settings->gate->decide(RequestTarget::fromUri('/blog'))->isAllowed())->toBeTrue()
        ->and($settings->urls)->toBeNull();
});

it('redirects as soon as a front-end URL is set, and maps URLs to it', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com'], BACKEND);

    expect($settings->gate->decide(RequestTarget::fromUri('/blog'))->location)->toBe('https://www.example.com/blog')
        ->and($settings->urls?->toFront('https://cms.example.com/a'))->toBe('https://www.example.com/a');
});

it('reads the options with their defaults', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'redirect_status' => '301', 'preserve_query' => 'false', 'allow' => ['/wc-api', 12, ''], 'clean_head' => false], BACKEND);

    expect($settings->cleanHead)->toBeFalse()
        ->and($settings->disableXmlRpc)->toBeTrue()
        ->and($settings->gate->decide(RequestTarget::fromUri('/a?b=c'))->location)->toBe('https://www.example.com/a')
        ->and($settings->gate->decide(RequestTarget::fromUri('/wc-api/x'))->isAllowed())->toBeTrue();
});

it('ignores a setting of the wrong type', function (): void {
    $settings = Settings::fromArray(['frontend_url' => ['x'], 'allow' => 'nope', 'blocked_status' => 'abc', 'mode' => 'page'], BACKEND);

    expect($settings->gate->decide(RequestTarget::fromUri('/blog'))->status)->toBe(404);
});

it('refuses an unknown mode or redirect status, naming the choices', function (string $key, string $value): void {
    Settings::fromArray(['frontend_url' => 'https://www.example.com', $key => $value], BACKEND);
})->throws(InvalidSettingsException::class)->with([['mode', 'maybe'], ['redirect_status', '303']]);

it('reads the front end of each language', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'frontend_urls' => ['fr' => 'https://www.example.fr', 'de' => '', 5 => 'https://x.test', 'es' => 12]], BACKEND);

    expect(array_keys($settings->languageFronts))->toBe(['fr'])
        ->and($settings->languageFronts['fr']->toString())->toBe('https://www.example.fr');
});

it('reads the CORS, cache and link settings', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'cors' => ['origins' => ['https://*.example.com'], 'credentials' => true], 'rest_cache' => ['max_age' => 5], 'per_page_max' => 20, 'rewrite_links' => false], BACKEND);

    expect($settings->cors?->allows('https://app.example.com'))->toBeTrue()
        ->and($settings->cors?->allows('https://www.example.com'))->toBeTrue()
        ->and($settings->restCache?->cacheControl('GET', 200, false, false, '/wp/v2/posts'))->toContain('max-age=5')
        ->and($settings->perPageMax)->toBe(20)
        ->and($settings->rewriteLinks)->toBeFalse();
});

it('leaves CORS, the cache and the ceiling off without a front end or when switched off', function (): void {
    $bare = Settings::fromArray([], BACKEND);
    $off = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'cors' => ['enabled' => false], 'rest_cache' => ['enabled' => false], 'per_page_max' => 100], BACKEND);

    expect($bare->cors)->toBeNull()->and($bare->restCache)->toBeNull()->and($bare->perPageMax)->toBeNull()
        ->and($off->cors)->toBeNull()->and($off->restCache)->toBeNull()->and($off->perPageMax)->toBeNull();
});
