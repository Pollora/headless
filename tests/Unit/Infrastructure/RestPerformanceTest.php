<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Cache\Etag;
use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Infrastructure\WordPress\RestPerformance;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function perfRequest(string $method = 'GET', string $route = '/wp/v2/posts', ?string $authorization = null, ?string $ifNoneMatch = null, mixed $perPage = null): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_method')->andReturn($method);
    $request->shouldReceive('get_route')->andReturn($route);
    $request->shouldReceive('get_header')->with('authorization')->andReturn($authorization);
    $request->shouldReceive('get_header')->with('if_none_match')->andReturn($ifNoneMatch);
    $request->shouldReceive('get_param')->with('per_page')->andReturn($perPage);

    return $request;
}

function perfResponse(int $status = 200, array $headers = [], mixed $data = ['id' => 1]): WP_HTTP_Response
{
    $response = Mockery::mock('WP_HTTP_Response');
    $response->shouldReceive('get_status')->andReturn($status);
    $response->shouldReceive('get_headers')->andReturn($headers);
    $response->shouldReceive('get_data')->andReturn($data);

    return $response;
}

it('hooks what it was given and nothing else', function (): void {
    Filters\expectAdded('rest_post_dispatch')->once();
    Filters\expectAdded('rest_pre_serve_request')->once();
    Filters\expectAdded('rest_pre_dispatch')->once();

    (new RestPerformance(new RestCachePolicy, 20))->register(new WordPressHooks);
});

it('hooks nothing without a cache policy and a ceiling', function (): void {
    Filters\expectAdded('rest_post_dispatch')->never();
    Filters\expectAdded('rest_pre_dispatch')->never();

    (new RestPerformance(null, null))->register(new WordPressHooks);
});

it('marks an anonymous GET as public', function (): void {
    Functions\when('is_user_logged_in')->justReturn(false);
    $response = perfResponse();
    $response->shouldReceive('header')->once()->with('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=60');

    expect((new RestPerformance(new RestCachePolicy, null))->markCacheable($response, null, perfRequest()))->toBe($response);
});

it('does not mark a logged-in user\'s answer, an Authorization request or something that is not a response', function (): void {
    $module = new RestPerformance(new RestCachePolicy, null);

    Functions\when('is_user_logged_in')->justReturn(true);
    $loggedIn = perfResponse();
    $loggedIn->shouldReceive('header')->never();
    $module->markCacheable($loggedIn, null, perfRequest());

    Functions\when('is_user_logged_in')->justReturn(false);
    $authorized = perfResponse();
    $authorized->shouldReceive('header')->never();
    $module->markCacheable($authorized, null, perfRequest(authorization: 'Bearer x'));

    expect($module->markCacheable('not a response', null, perfRequest()))->toBe('not a response');
});

it('adds an ETag to a public answer, and answers 304 when the client holds it', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');
    $etag = Etag::forBody('{"id":1}');
    $sent = [];
    $statuses = [];
    $module = new RestPerformance(new RestCachePolicy, null, function (string $line) use (&$sent): void {
        $sent[] = $line;
    }, function (int $status) use (&$statuses): void {
        $statuses[] = $status;
    });

    $public = ['Cache-Control' => 'public, max-age=60'];

    expect($module->validate(false, perfResponse(200, $public), perfRequest()))->toBeFalse()
        ->and($sent)->toBe(['ETag: '.$etag])
        ->and($statuses)->toBe([]);

    $sent = [];

    expect($module->validate(false, perfResponse(200, $public), perfRequest(ifNoneMatch: $etag)))->toBeTrue()
        ->and($statuses)->toBe([304]);
});

it('leaves a private answer, an answer already served and a non-response alone', function (): void {
    $sent = [];
    $module = new RestPerformance(new RestCachePolicy, null, function (string $line) use (&$sent): void {
        $sent[] = $line;
    });

    expect($module->validate(false, perfResponse(200, ['Cache-Control' => 'no-cache']), perfRequest()))->toBeFalse()
        ->and($module->validate(true, perfResponse(200, ['Cache-Control' => 'public']), perfRequest()))->toBeTrue()
        ->and($module->validate(false, 'x', perfRequest()))->toBeFalse()
        ->and($sent)->toBe([]);
});

it('uses header() and status_header() by default', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\expect('status_header')->once()->with(304);
    $module = new RestPerformance(new RestCachePolicy, null);

    expect(@$module->validate(false, perfResponse(200, ['Cache-Control' => 'public']), perfRequest(ifNoneMatch: Etag::forBody('{"id":1}'))))->toBeTrue();
});

it('lowers per_page to the ceiling and leaves smaller values and other requests alone', function (): void {
    $module = new RestPerformance(null, 20);

    $big = perfRequest(perPage: '100');
    $big->shouldReceive('set_param')->once()->with('per_page', 20);
    $small = perfRequest(perPage: 5);
    $small->shouldReceive('set_param')->never();
    $none = perfRequest();
    $none->shouldReceive('set_param')->never();

    expect($module->capPerPage(null, null, $big))->toBeNull()
        ->and($module->capPerPage(null, null, $small))->toBeNull()
        ->and($module->capPerPage('earlier', null, $none))->toBe('earlier');
});
