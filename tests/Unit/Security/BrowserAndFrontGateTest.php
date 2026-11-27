<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Cors\CorsRequest;
use Pollora\Headless\Domain\Front\AllowList;
use Pollora\Headless\Domain\Front\FrontGatePolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\RequestTarget;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Front\UrlPath;
use Pollora\Headless\Infrastructure\WordPress\TemplateRedirectGate;

afterEach(function (): void {
    unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
});

function edgeGate(FrontMode $mode, int &$count): TemplateRedirectGate
{
    $policy = new FrontGatePolicy($mode, AllowList::defaults(), Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'));

    return new TemplateRedirectGate($policy, static function () use (&$count): void {
        $count++;
    });
}

it('lets every default entry through, and what lies below it', function (string $path): void {
    expect(AllowList::defaults()->allows(RequestTarget::fromUri($path)))->toBeTrue();
})->with([
    '/wp-admin', '/wp-admin/', '/wp-admin/options.php', '/wp-login.php', '/wp-json', '/wp-json/wp/v2/posts', '/graphql', '/wp-cron.php',
    '/wp-content/uploads/2026/a.jpg', '/xmlrpc.php', '/wp-sitemap.xml', '/robots.txt',
]);

it('keeps what only starts like an allowed path behind the gate', function (string $path): void {
    expect(AllowList::defaults()->allows(RequestTarget::fromUri($path)))->toBeFalse();
})->with([
    '/wp-adminx', '/wp-jsonx/a', '/graphql-docs', '/robots.txtx', '/wp-content/uploads-old/a', '/wp-content/plugins/a.php', '/', '/blog/wp-admin',
]);

it('does not let dot segments walk out of an allowed path', function (string $uri): void {
    expect(AllowList::defaults()->allows(RequestTarget::fromUri($uri)))->toBeFalse();
})->with(['/wp-admin/..', '/wp-admin/../secret', '/wp-json/%2e%2e/secret', '/wp-admin/%2E./secret', '/wp-json/wp/../../secret']);

it('resolves dot segments to the path a server would serve', function (string $uri, string $path): void {
    expect(RequestTarget::fromUri($uri)->path)->toBe($path);
})->with([
    ['/blog/a/..', '/blog/'],
    ['/blog/a/.', '/blog/a/'],
    ['/blog/./a', '/blog/a'],
    ['/..', '/'],
    ['blog', '/blog'],
    ['//blog', '/blog'],
]);

it('keeps a second question mark in the query', function (): void {
    $target = RequestTarget::fromUri('/a?next=/b?c=1');

    expect($target->path)->toBe('/a')->and($target->query)->toBe('next=/b?c=1');
});

it('allows an extra prefix next to the defaults, written with or without slashes', function (): void {
    $list = AllowList::defaults()->with('health/', '/hooks');

    expect($list->prefixes)->toBe([...AllowList::defaults()->prefixes, '/health', '/hooks'])
        ->and($list->allows(RequestTarget::fromUri('/health')))->toBeTrue()
        ->and($list->allows(RequestTarget::fromUri('/hooks/x')))->toBeTrue();
});

it('keeps the prefixes a list, each once', function (): void {
    expect((new AllowList(['a', '/a/', 'b']))->prefixes)->toBe(['/a', '/b']);
});

it('reads a URL written with blanks around it', function (): void {
    expect(Origin::fromUrl("  https://www.example.com \n")->toString())->toBe('https://www.example.com');
});

it('moves a URL to the front whatever the case of its scheme and port', function (): void {
    $mapper = new UrlMapper(Origin::fromUrl('https://cms.example.com:8443'), Origin::fromUrl('https://www.example.com'));

    expect($mapper->toFront('HTTPS://cms.example.com:8443/a'))->toBe('https://www.example.com/a')
        ->and($mapper->toFront('https://cms.example.com/a'))->toBe('https://cms.example.com/a');
});

it('gives the path and query of an address', function (string $url, string $path): void {
    expect(UrlPath::of($url))->toBe($path);
})->with([
    ['https://www.example.com/a/?b=1', '/a/?b=1'],
    ['https://www.example.com/a/', '/a/'],
    ['https://www.example.com', '/'],
    ['https://www.example.com?b=1', '/?b=1'],
]);

it('keeps matching the origins listed after a sub-domain pattern', function (): void {
    $cors = new CorsPolicy(['https://*.a.test', 'https://b.test']);

    expect($cors->allows('https://b.test'))->toBeTrue()->and($cors->allows('https://x.a.test'))->toBeTrue();
});

it('reads an origin or a pattern whatever its case and the blanks around it', function (): void {
    $cors = new CorsPolicy(['  HTTPS://*.Example.COM ', ' https://other.test ']);

    expect($cors->allows('https://app.example.com'))->toBeTrue()
        ->and($cors->allows('https://other.test'))->toBeTrue()
        ->and($cors->allows('https://other.test/'))->toBeTrue();
});

it('matches a pattern with a port on that port only', function (): void {
    $cors = new CorsPolicy(['https://*.example.com:8443']);

    expect($cors->allows('https://app.example.com:8443'))->toBeTrue()
        ->and($cors->allows('https://app.example.com'))->toBeFalse()
        ->and($cors->allows('https://app.example.com:9000'))->toBeFalse();
});

it('says which response headers a script may read', function (): void {
    $decision = (new CorsPolicy(['https://a.test']))->decide(new CorsRequest('https://a.test'));

    expect($decision->headers)->toBe([
        'Vary' => 'Origin',
        'Access-Control-Allow-Origin' => 'https://a.test',
        'Access-Control-Expose-Headers' => 'X-WP-Total, X-WP-TotalPages, Link',
    ]);
});

it('answers a preflight with the headers asked for only when each one is allowed', function (?string $asked, string $expected): void {
    $cors = new CorsPolicy(['https://a.test'], headers: ['Authorization', 'Content-Type']);

    expect($cors->decide(new CorsRequest('https://a.test', 'options', 'GET', $asked))->headers['Access-Control-Allow-Headers'])->toBe($expected);
})->with([
    'mixed case' => ['AUTHORIZATION , content-type', 'AUTHORIZATION, content-type'],
    'an empty entry' => [',authorization,', 'authorization'],
    'one too many' => ['authorization, x-evil', 'Authorization, Content-Type'],
    'nothing' => ['', 'Authorization, Content-Type'],
    'no header' => [null, 'Authorization, Content-Type'],
]);

it('does not take an empty requested method for a preflight', function (): void {
    expect((new CorsRequest('https://a.test', 'OPTIONS', ''))->isPreflight())->toBeFalse()
        ->and((new CorsRequest('https://a.test', 'options', 'GET'))->isPreflight())->toBeTrue();
});

it('never redirects when the gate answers with a page or JSON', function (FrontMode $mode, string $body): void {
    $_SERVER['REQUEST_URI'] = '/blog';
    Functions\expect('wp_redirect')->never();
    Functions\expect('status_header')->once()->with(404);
    Functions\expect('nocache_headers')->once();
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('get_bloginfo')->alias(static fn (string $key): string => $key === 'name' ? 'My <Site>' : '');
    Functions\when('esc_html')->alias(static fn (string $text): string => htmlspecialchars($text));
    $count = 0;

    ob_start();
    edgeGate($mode, $count)->handle();

    expect(ob_get_clean())->toBe($body)->and($count)->toBe(1);
})->with([
    [FrontMode::Page, '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>My &lt;Site&gt;</title><p>This site has no public front end.</p>'],
    [FrontMode::Json, '{"status":404,"message":"This site has no public front end."}'],
]);

it('takes an unreadable address for the home page', function (): void {
    $_SERVER['REQUEST_URI'] = ['/wp-admin'];
    $_SERVER['REQUEST_METHOD'] = ['POST'];
    Functions\expect('wp_redirect')->once()->with('https://www.example.com/', 302, 'Pollora Headless');
    $count = 0;

    edgeGate(FrontMode::Redirect, $count)->handle();

    expect($count)->toBe(1);
});
