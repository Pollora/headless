<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;

function site(): SiteContext
{
    return new SiteContext(2, 'fr_FR', true, 'https://cms.example.com/wp');
}

it('describes a working setup without warnings', function (): void {
    $report = StatusReport::build(Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com/wp'), site(), true, true);
    $rows = array_column($report->rows, 1, 0);

    expect($report->warnings)->toBeEmpty()
        ->and($rows['Site'])->toBe('#2, fr_FR (multisite)')
        ->and($rows['WordPress URL'])->toBe('https://cms.example.com/wp')
        ->and($rows['Front gate'])->toBe('redirect (302)')
        ->and($rows['WP-Cron'])->toContain('system cron');
});

it('warns about a missing front-end URL, a looping one and a missing theme', function (): void {
    $none = StatusReport::build(Settings::fromArray([], 'https://cms.example.com'), site(), false, false);
    $loop = StatusReport::build(Settings::fromArray(['frontend_url' => 'https://cms.example.com'], 'https://cms.example.com'), site(), true, false);

    expect($none->warnings)->toHaveCount(2)
        ->and($none->warnings[0])->toContain('No front-end URL')
        ->and($none->warnings[1])->toContain('theme install')
        ->and($loop->warnings)->toHaveCount(1)
        ->and($loop->warnings[0])->toContain('would loop')
        ->and(array_column($none->rows, 1, 0)['Front gate'])->toBe('off');
});
