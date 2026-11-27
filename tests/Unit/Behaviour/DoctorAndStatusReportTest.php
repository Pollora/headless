<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectRules;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Status\Check;
use Pollora\Headless\Infrastructure\Status\Doctor;
use Pollora\Headless\Infrastructure\Status\DoctorFacts;
use Pollora\Headless\Infrastructure\Status\StatusReport;
use Pollora\Headless\Infrastructure\WordPress\SiteContext;

/*
 * What `wp headless doctor` and `wp headless status` print, word for word.
 */

const REPORT_SITE = [
    'frontend_url' => 'https://www.example.com',
    'revalidation' => ['targets' => [['name' => 'next', 'url' => 'https://www.example.com/api/revalidate', 'secret' => 'k'], ['name' => 'cdn', 'url' => 'https://cdn.example.com/hook', 'secret' => 'j']]],
];

function reportFacts(array $over = []): DoctorFacts
{
    $facts = array_replace(['users' => 401, 'posts' => 200, 'permalinks' => '/%postname%/', 'debug' => false, 'theme' => true, 'cronOff' => false, 'scheduler' => true, 'graphql' => false, 'introspection' => null, 'redirects' => [], 'seo' => null], $over);

    return new DoctorFacts($facts['users'], $facts['posts'], $facts['permalinks'], $facts['debug'], $facts['theme'], $facts['cronOff'], $facts['scheduler'], $facts['graphql'], $facts['introspection'], new RedirectRules($facts['redirects']), $facts['seo']);
}

/** @return list<array{string, string, string, string}> */
function reportChecks(array $config, DoctorFacts $facts, string $backend = 'https://cms.example.com'): array
{
    return array_map(static fn (Check $check): array => array_values($check->toArray()), Doctor::run(Settings::fromArray($config, $backend), $facts));
}

/** @return list<string> the messages of the checks with one of these ids */
function reportMessages(array $ids, array $config, DoctorFacts $facts, string $backend = 'https://cms.example.com'): array
{
    return array_values(array_map(
        static fn (array $check): string => $check[1].': '.$check[2],
        array_filter(reportChecks($config, $facts, $backend), static fn (array $check): bool => in_array($check[0], $ids, true)),
    ));
}

it('prints every check of a well set up site', function (): void {
    expect(reportChecks(REPORT_SITE, reportFacts()))->toBe([
        ['front-url', 'ok', 'Front end: https://www.example.com', ''],
        ['cors', 'ok', 'CORS answers for the listed origins only.', ''],
        ['users-route', 'ok', 'The users route answers 401 to a visitor.', ''],
        ['rest-open', 'ok', 'The REST API answers visitors.', ''],
        ['permalinks', 'ok', 'Pretty permalinks: /%postname%/', ''],
        ['preview', 'ok', 'Preview goes to https://www.example.com/api/preview', ''],
        ['revalidation', 'ok', 'Revalidation runs on Action Scheduler.', ''],
        ['redirects', 'ok', '0 redirect rule(s), no loop.', ''],
        ['theme', 'ok', 'A valid theme is active.', ''],
        ['seo', 'ok', 'No SEO plugin: `headless_seo` is empty.', ''],
        ['debug-display', 'ok', 'PHP errors are not printed in the answers.', ''],
    ]);
});

it('prints what to do about each thing that is wrong', function (): void {
    $facts = reportFacts(['users' => 200, 'posts' => 403, 'permalinks' => '', 'debug' => true, 'theme' => false, 'scheduler' => false, 'graphql' => true, 'introspection' => true, 'seo' => 'yoast']);

    expect(reportChecks(['tokens' => ['enabled' => false], 'rest_plugins' => ['enabled' => true]], $facts))->toBe([
        ['front-url', 'warning', 'No front-end URL: the public front of WordPress is left as it is.', 'Set HEADLESS_FRONTEND_URL.'],
        ['cors', 'warning', 'No CORS policy: WordPress reflects any origin and allows credentials.', 'Set the front-end URL, or list `cors.origins`.'],
        ['users-route', 'fail', 'An anonymous visitor can list the users (`/wp/v2/users`): their logins can be guessed and attacked.', 'Keep `restrict_users` on.'],
        ['rest-open', 'fail', 'The posts route answers 403 to a visitor: a plugin locks the REST API, and a front end cannot read the content.', 'Allow anonymous reads, or give the front end an API token.'],
        ['tokens', 'warning', 'API tokens are off.', 'A front end that reads private content needs one.'],
        ['permalinks', 'fail', 'Plain permalinks: `/wp-json/` and the redirects of the front end need pretty ones.', 'Settings, Permalinks: pick any structure but "Plain".'],
        ['preview', 'warning', 'Preview is off: editors cannot see a draft on the front end.', 'Set `preview.url` to the preview route of the front end.'],
        ['revalidation', 'warning', 'No revalidation target: the front end is not told when content changes.', 'Add one under `revalidation.targets`.'],
        ['graphql', 'fail', 'Anyone can introspect the GraphQL schema.', 'Keep `graphql.introspection` false.'],
        ['redirects', 'ok', '0 redirect rule(s), no loop.', ''],
        ['rest-plugins', 'warning', 'REST requests load only the plugins you listed: any plugin left out loses its routes, types, fields and login there.', 'Check `rest_plugins.keep` after each plugin you add.'],
        ['theme', 'fail', 'No valid theme: WordPress falls back to recovery mode.', 'Run `wp headless theme install --activate`.'],
        ['seo', 'ok', 'SEO plugin: yoast', ''],
        ['debug-display', 'warning', 'PHP errors are printed in the answers.', 'Set WP_DEBUG_DISPLAY to false: a notice inside a JSON answer breaks the client and leaks paths.'],
    ]);
});

it('says when the routes could not be tried', function (): void {
    expect(reportMessages(['users-route', 'rest-open'], REPORT_SITE, reportFacts(['users' => null, 'posts' => null])))->toBe([
        'warning: The users route could not be tried.',
        'warning: The posts route could not be tried.',
    ]);
});

it('warns of each address that is not HTTPS, unless it is a local one', function (string $host, bool $local): void {
    $warnings = reportMessages(['https'], ['frontend_url' => 'http://'.$host.':3000'], reportFacts(), 'http://'.$host);

    expect($warnings)->toBe($local ? [] : [
        'warning: The front address is not HTTPS: tokens and sessions travel in clear.',
        'warning: The WordPress address is not HTTPS: tokens and sessions travel in clear.',
    ]);
})->with([
    ['localhost', true], ['127.0.0.1', true], ['site.test', true], ['site.local', true], ['site.ddev.site', true],
    ['example.com', false], ['test.example.com', false], ['local.example.com', false], ['ddev.site.example.com', false], ['localhost.example.com', false],
]);

it('warns of a revalidation target that is not HTTPS, unless it is a local one', function (string $url, bool $warned): void {
    $config = ['revalidation' => ['targets' => [['name' => 'next', 'url' => $url, 'secret' => 'k']]]];

    expect(reportMessages(['revalidation-https'], $config, reportFacts()))->toBe($warned ? ['warning: The target "next" is not HTTPS.'] : []);
})->with([['http://hooks.example.com/r', true], ['https://hooks.example.com/r', false], ['http://localhost:3000/r', false]]);

it('says how revalidation is delivered without Action Scheduler', function (bool $cronOff, string $message): void {
    expect(reportMessages(['revalidation'], REPORT_SITE, reportFacts(['scheduler' => false, 'cronOff' => $cronOff])))->toBe(['warning: '.$message]);
})->with([
    [true, 'Revalidation goes through WP-Cron, which is disabled: a system cron must call wp-cron.php.'],
    [false, 'Revalidation goes through WP-Cron, which runs only when a page is visited.'],
]);

it('says a closed GraphQL schema is closed', function (): void {
    expect(reportMessages(['graphql'], REPORT_SITE, reportFacts(['graphql' => true, 'introspection' => false])))->toBe(['ok: WPGraphQL is active and its schema is closed to visitors.']);
});

/** @param list<array{string, string}> $pairs */
function reportRedirects(array $pairs, array $more = []): array
{
    $rules = array_map(static fn (array $pair): Redirect => new Redirect($pair[0], $pair[1]), $pairs);

    return reportMessages(['redirects', 'redirect-loop', 'redirect-chain'], REPORT_SITE, reportFacts(['redirects' => [...$rules, ...$more]]));
}

it('counts the rules when none loops or chains, the gone ones and the patterns included', function (): void {
    expect(reportRedirects([['/a', '/b']], [new Redirect('/gone', '', 410), new Redirect('/p/(.*)', '/p/x/$1', 301, true)]))->toBe(['ok: 3 redirect rule(s), no loop.']);
});

it('does not follow a gone rule or a pattern as the start of a chain', function (): void {
    $rules = [new Redirect('/b', '/c'), new Redirect('/c', '/d')];
    $starts = [new Redirect('/gone', '/b', 410), new Redirect('/x(.*)', '/b', 301, true)];

    expect(reportRedirects([], [...$starts, ...$rules]))->toBe(['warning: The redirect of /b takes 2 hops.']);
});

it('fails every rule of a circle', function (): void {
    expect(reportRedirects([['/a/', 'https://www.example.com/b/'], ['/b', '/a']]))->toBe([
        'fail: The redirect of /a/ ends up back where it started.',
        'fail: The redirect of /b ends up back where it started.',
    ]);
});

it('fails a rule that only comes back to its own address written another way', function (): void {
    expect(reportRedirects([['/a/', '/a?x=1']]))->toBe(['fail: The redirect of /a/ ends up back where it started.']);
});

it('fails a rule that leads into a circle it is not part of', function (): void {
    expect(reportRedirects([['/in', '/a'], ['/a', '/b'], ['/b', '/a']]))->toContain('fail: The redirect of /in ends up back where it started.');
});

it('warns of the rules that take several hops, with their number, and stops following after ten', function (): void {
    $pairs = array_map(static fn (int $i): array => ['/p'.$i, '/p'.($i + 1)], range(0, 11));
    $messages = reportRedirects($pairs);

    expect($messages)->toHaveCount(11)
        ->and($messages[0])->toBe('warning: The redirect of /p0 takes 10 hops.')
        ->and($messages[2])->toBe('warning: The redirect of /p2 takes 10 hops.')
        ->and($messages[3])->toBe('warning: The redirect of /p3 takes 9 hops.')
        ->and($messages[10])->toBe('warning: The redirect of /p10 takes 2 hops.');
});

function reportLanguages(bool $active): Languages
{
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn($active);
    $languages->shouldReceive('plugin')->andReturn('polylang');
    $languages->shouldReceive('all')->andReturn(['en', 'fr']);

    return $languages;
}

it('prints every row of the status of a working site', function (): void {
    $report = StatusReport::build(Settings::fromArray(REPORT_SITE, 'https://cms.example.com/wp'), new SiteContext(1, 'en_US', false, 'https://cms.example.com/wp'), true, false, reportLanguages(true), true);

    expect($report->rows)->toBe([
        ['Site', '#1, en_US'],
        ['WordPress URL', 'https://cms.example.com/wp'],
        ['Front-end URL', 'https://www.example.com'],
        ['Front gate', 'redirect (302)'],
        ['Languages', 'polylang (en, fr)'],
        ['Preview', 'https://www.example.com/api/preview'],
        ['Revalidation', 'next, cdn (Action Scheduler)'],
        ['Head clean-up', 'on'],
        ['XML-RPC', 'off'],
        ['Feeds', 'off'],
        ['Core sitemap', 'off'],
        ['Origin noindex', 'on'],
        ['Empty theme', 'valid theme active'],
        ['WP-Cron', 'runs on page loads'],
    ])->and($report->warnings)->toBe([]);
});

it('prints every row of the status of a site with everything left on', function (): void {
    $config = ['clean_head' => false, 'disable_xmlrpc' => false, 'disable_feeds' => false, 'disable_sitemaps' => false, 'noindex_origin' => false];
    $report = StatusReport::build(Settings::fromArray($config, 'https://cms.example.com'), new SiteContext(3, 'de_DE', true, 'https://cms.example.com'), false, true, reportLanguages(false));

    expect($report->rows)->toBe([
        ['Site', '#3, de_DE (multisite)'],
        ['WordPress URL', 'https://cms.example.com'],
        ['Front-end URL', 'not set'],
        ['Front gate', 'off'],
        ['Languages', 'single language'],
        ['Preview', 'off'],
        ['Revalidation', 'off'],
        ['Head clean-up', 'off'],
        ['XML-RPC', 'on'],
        ['Feeds', 'on'],
        ['Core sitemap', 'on'],
        ['Origin noindex', 'off'],
        ['Empty theme', 'missing'],
        ['WP-Cron', 'disabled (a system cron must run it)'],
    ]);
});
