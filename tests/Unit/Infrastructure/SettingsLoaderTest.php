<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Config\SettingsLoader;

$network = ['frontend_url' => 'https://www.example.com', 'sites' => [2 => ['frontend_url' => 'https://blog.example.com', 'mode' => 'page']]];

it('keeps the shared settings for a site that has none of its own', function () use ($network): void {
    expect(SettingsLoader::merge($network, null, 3))->toBe(['frontend_url' => 'https://www.example.com']);
});

it('lets the settings of one site of a network override the shared ones', function () use ($network): void {
    expect(SettingsLoader::merge($network, null, 2))->toBe(['frontend_url' => 'https://blog.example.com', 'mode' => 'page']);
});

it('uses the short constant only when no front-end URL is set', function () use ($network): void {
    expect(SettingsLoader::merge([], 'https://short.example.com', 1))->toBe(['frontend_url' => 'https://short.example.com'])
        ->and(SettingsLoader::merge($network, 'https://short.example.com', 1)['frontend_url'])->toBe('https://www.example.com');
});
