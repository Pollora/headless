<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Exceptions\ThemeInstallException;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;

function scratchDirectory(): string
{
    $directory = sys_get_temp_dir().'/headless-'.bin2hex(random_bytes(6));
    mkdir($directory);

    return $directory;
}

it('copies the empty theme into the themes directory, once', function (): void {
    $root = scratchDirectory();
    $theme = new StubTheme($root, dirname(__DIR__, 3).'/resources/theme');

    expect($theme->isInstalled())->toBeFalse()
        ->and($theme->install())->toBeTrue()
        ->and($theme->isInstalled())->toBeTrue()
        ->and(is_file($root.'/headless/index.php'))->toBeTrue()
        ->and(file_get_contents($root.'/headless/style.css'))->toContain('Theme Name: Headless')
        ->and($theme->install())->toBeFalse();
});

it('says why it cannot write', function (): void {
    $file = scratchDirectory().'/not-a-directory';
    touch($file);

    (new StubTheme($file, dirname(__DIR__, 3).'/resources/theme'))->install();
})->throws(ThemeInstallException::class, 'Cannot write');

it('fails when a file of the theme cannot be copied', function (): void {
    (new StubTheme(scratchDirectory(), scratchDirectory().'/missing-source'))->install();
})->throws(ThemeInstallException::class);
