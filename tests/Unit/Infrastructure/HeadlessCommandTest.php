<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;
use Pollora\Headless\Infrastructure\WpCli\HeadlessCommand;
use Pollora\Headless\Infrastructure\WpCli\Output;

function recorder(): Output
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $messages = [];

        public function line(string $message): void
        {
            $this->messages[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->messages[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->messages[] = 'error: '.$message;
        }
    };
}

function commandFor(Output $output, string $themeRoot, ?array &$activated = null, string $front = 'https://www.example.com'): HeadlessCommand
{
    $activated = [];

    return new HeadlessCommand(
        static fn (): StatusReport => StatusReport::build(Settings::fromArray($front === '' ? [] : ['frontend_url' => $front], 'https://cms.example.com'), new SiteContext(1, 'en_US', false, 'https://cms.example.com'), true, false),
        static fn (): StubTheme => new StubTheme($themeRoot, dirname(__DIR__, 3).'/resources/theme'),
        static function (string $slug) use (&$activated): void {
            $activated[] = $slug;
        },
        $output,
    );
}

it('prints the status rows and a success when nothing is wrong', function (): void {
    $output = recorder();
    commandFor($output, sys_get_temp_dir())->status();

    expect($output->messages)->toContain('line: Front gate:     redirect (302)')
        ->and(end($output->messages))->toBe('success: Nothing to report.');
});

it('prints the warnings of the status', function (): void {
    $output = recorder();
    commandFor($output, sys_get_temp_dir(), front: '')->status();

    expect(implode("\n", $output->messages))->toContain('warning: No front-end URL');
});

it('installs and activates the empty theme', function (): void {
    $root = sys_get_temp_dir().'/headless-cmd-'.bin2hex(random_bytes(6));
    mkdir($root);
    $output = recorder();
    $activated = [];

    commandFor($output, $root, $activated)->theme(['install'], ['activate' => true]);

    expect($output->messages)->toBe(['success: Installed the empty theme.', 'success: Activated the empty theme.'])
        ->and($activated)->toBe(['headless']);

    $again = recorder();
    commandFor($again, $root)->theme(['install']);

    expect($again->messages)->toBe(['success: The empty theme is already installed.']);
});

it('explains the usage and reports an install failure', function (): void {
    $output = recorder();
    commandFor($output, sys_get_temp_dir())->theme(['uninstall']);

    $file = sys_get_temp_dir().'/headless-file-'.bin2hex(random_bytes(6));
    touch($file);
    $failed = recorder();
    commandFor($failed, $file)->theme(['install']);

    expect($output->messages)->toBe(['error: Usage: wp headless theme install [--activate]'])
        ->and($failed->messages[0])->toStartWith('error: Cannot write');
});
