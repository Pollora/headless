<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectRules;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Status\Check;
use Pollora\Headless\Infrastructure\Status\CheckLevel;
use Pollora\Headless\Infrastructure\Status\Doctor;
use Pollora\Headless\Infrastructure\Status\DoctorFacts;
use Pollora\Headless\Infrastructure\WpCli\HeadlessCommand;
use Pollora\Headless\Infrastructure\WpCli\Output;

/**
 * @param  list<Redirect>  $redirects
 */
function doctorFacts(array $redirects = [], int $users = 401, int $posts = 200, string $permalinks = '/%postname%/', bool $graphql = false, ?bool $introspection = null, bool $scheduler = true, bool $cronOff = false, bool $debug = false, bool $theme = true, ?string $seo = 'yoast'): DoctorFacts
{
    return new DoctorFacts($users, $posts, $permalinks, $debug, $theme, $cronOff, $scheduler, $graphql, $introspection, new RedirectRules($redirects), $seo);
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, CheckLevel>
 */
function doctorLevels(array $config, DoctorFacts $facts): array
{
    $levels = [];

    foreach (Doctor::run(Settings::fromArray($config, 'https://cms.example.com'), $facts) as $check) {
        $levels[$check->id] = $levels[$check->id] ?? $check->level;

        if ($check->level === CheckLevel::Fail) {
            $levels[$check->id] = CheckLevel::Fail;
        }
    }

    return $levels;
}

const DOCTOR_GOOD = [
    'frontend_url' => 'https://www.example.com',
    'preview' => ['url' => 'https://www.example.com/api/preview'],
    'revalidation' => ['targets' => [['name' => 'next', 'url' => 'https://www.example.com/api/revalidate', 'secret' => 's3cret']]],
];

it('finds nothing wrong with a well set up site', function (): void {
    $checks = Doctor::run(Settings::fromArray(DOCTOR_GOOD, 'https://cms.example.com'), doctorFacts());

    expect(array_filter($checks, static fn (Check $check): bool => $check->level !== CheckLevel::Ok))->toBe([])
        ->and(Doctor::failed($checks))->toBeFalse();
});

it('warns when there is no front end, preview or revalidation', function (): void {
    $levels = doctorLevels([], doctorFacts());

    expect($levels['front-url'])->toBe(CheckLevel::Warning)
        ->and($levels['preview'])->toBe(CheckLevel::Warning)
        ->and($levels['revalidation'])->toBe(CheckLevel::Warning)
        ->and($levels['cors'])->toBe(CheckLevel::Warning);
});

it('fails when the front end has the host of WordPress', function (): void {
    expect(doctorLevels(['frontend_url' => 'https://cms.example.com:8080'] + DOCTOR_GOOD, doctorFacts())['front-host'] ?? null)->toBeNull()
        ->and(doctorLevels(['frontend_url' => 'https://cms.example.com'] + DOCTOR_GOOD, doctorFacts())['front-host'])->toBe(CheckLevel::Fail);
});

it('warns about a front end that is not HTTPS, except a local one', function (): void {
    expect(doctorLevels(['frontend_url' => 'http://front.example.net'] + DOCTOR_GOOD, doctorFacts())['https'])->toBe(CheckLevel::Warning)
        ->and(doctorLevels(['frontend_url' => 'http://front.test'] + DOCTOR_GOOD, doctorFacts()))->not->toHaveKey('https');
});

it('fails when a visitor can list users or the REST API is locked', function (): void {
    $open = doctorLevels(DOCTOR_GOOD, doctorFacts(users: 200));
    $locked = doctorLevels(DOCTOR_GOOD, doctorFacts(posts: 401));
    $untried = doctorLevels(DOCTOR_GOOD, new DoctorFacts(null, null, '/%postname%/', false, true, false, true, false, null, new RedirectRules([]), null));

    expect($open['users-route'])->toBe(CheckLevel::Fail)
        ->and($locked['rest-open'])->toBe(CheckLevel::Fail)
        ->and($untried['users-route'])->toBe(CheckLevel::Warning)
        ->and($untried['rest-open'])->toBe(CheckLevel::Warning);
});

it('fails on plain permalinks and on a missing theme, warns on displayed errors', function (): void {
    $levels = doctorLevels(DOCTOR_GOOD, doctorFacts(permalinks: '', theme: false, debug: true));

    expect($levels['permalinks'])->toBe(CheckLevel::Fail)
        ->and($levels['theme'])->toBe(CheckLevel::Fail)
        ->and($levels['debug-display'])->toBe(CheckLevel::Warning);
});

it('warns about the cron that revalidation relies on without Action Scheduler', function (): void {
    expect(doctorLevels(DOCTOR_GOOD, doctorFacts(scheduler: false))['revalidation'])->toBe(CheckLevel::Warning)
        ->and(doctorLevels(DOCTOR_GOOD, doctorFacts(scheduler: false, cronOff: true))['revalidation'])->toBe(CheckLevel::Warning)
        ->and(doctorLevels(['revalidation' => ['targets' => [['name' => 'n', 'url' => 'http://front.example.net/r', 'secret' => 's']]]] + DOCTOR_GOOD, doctorFacts())['revalidation-https'])->toBe(CheckLevel::Warning);
});

it('fails when WPGraphQL lets visitors introspect', function (): void {
    expect(doctorLevels(DOCTOR_GOOD, doctorFacts(graphql: true, introspection: true))['graphql'])->toBe(CheckLevel::Fail)
        ->and(doctorLevels(DOCTOR_GOOD, doctorFacts(graphql: true, introspection: false))['graphql'])->toBe(CheckLevel::Ok)
        ->and(doctorLevels(DOCTOR_GOOD, doctorFacts()))->not->toHaveKey('graphql');
});

it('warns when API tokens are off', function (): void {
    expect(doctorLevels(['tokens' => ['enabled' => false]] + DOCTOR_GOOD, doctorFacts())['tokens'])->toBe(CheckLevel::Warning);
});

it('finds redirect loops and chains', function (): void {
    $loop = doctorLevels(DOCTOR_GOOD, doctorFacts([new Redirect('/a/', '/b/'), new Redirect('/b/', '/a/')]));
    $chain = doctorLevels(DOCTOR_GOOD, doctorFacts([new Redirect('/a/', '/b/'), new Redirect('/b/', '/c/')]));
    $fine = doctorLevels(DOCTOR_GOOD, doctorFacts([new Redirect('/a/', '/b/'), new Redirect('/gone/', '', 410)]));

    expect($loop['redirect-loop'])->toBe(CheckLevel::Fail)
        ->and($chain['redirect-chain'])->toBe(CheckLevel::Warning)
        ->and($fine['redirects'])->toBe(CheckLevel::Ok);
});

it('reports the SEO plugin', function (): void {
    $message = static fn (?string $seo): string => array_values(array_filter(Doctor::run(Settings::fromArray(DOCTOR_GOOD, 'https://cms.example.com'), doctorFacts(seo: $seo)), static fn (Check $check): bool => $check->id === 'seo'))[0]->message;

    expect($message('yoast'))->toBe('SEO plugin: yoast')->and($message(null))->toContain('No SEO plugin');
});

function doctorOutput(): Output
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

/**
 * @param  list<Check>  $checks
 */
function doctorCommand(Output $output, array $checks): HeadlessCommand
{
    return new HeadlessCommand(static fn () => throw new LogicException, static fn () => throw new LogicException, static function (): void {}, $output, static fn (): array => $checks);
}

it('prints each check, and stops with an error when one fails', function (): void {
    $output = doctorOutput();

    doctorCommand($output, [new Check('a', CheckLevel::Ok, 'All good'), new Check('b', CheckLevel::Warning, 'Careful', 'Do this'), new Check('c', CheckLevel::Fail, 'Broken', 'Fix that')])->doctor();

    expect($output->messages)->toBe(['line: [ok] All good', 'warning: [warning] Careful → Do this', 'warning: [fail] Broken → Fix that', 'error: The doctor found something that exposes the site or breaks the front end.']);
});

it('says so when nothing fails, and prints JSON on request', function (): void {
    $text = doctorOutput();
    $json = doctorOutput();
    $checks = [new Check('a', CheckLevel::Warning, 'Careful', 'Do this')];

    doctorCommand($text, $checks)->doctor();
    doctorCommand($json, $checks)->doctor([], ['format' => 'json']);

    expect($text->messages)->toBe(['warning: [warning] Careful → Do this', 'success: No failing check.'])
        ->and(json_decode(substr($json->messages[0], 6), true))->toBe([['id' => 'a', 'level' => 'warning', 'message' => 'Careful', 'fix' => 'Do this']])
        ->and(end($json->messages))->toBe('success: No failing check.');
});

it('has nothing to check without the closure', function (): void {
    $output = doctorOutput();

    (new HeadlessCommand(static fn () => throw new LogicException, static fn () => throw new LogicException, static function (): void {}, $output))->doctor();

    expect($output->messages)->toBe(['success: No failing check.']);
});
