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
