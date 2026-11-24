<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Infrastructure\WordPress\Cors;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function restRequest(string $method = 'GET'): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_method')->andReturn($method);

    return $request;
}

/**
 * @param  list<string>  $lines
 */
function corsModule(array &$lines): Cors
{
    return new Cors(new CorsPolicy(['https://www.example.com']), function (string $line, bool $replace) use (&$lines): void {
        $lines[] = ($replace ? 'set ' : 'add ').$line;
    });
}

afterEach(function (): void {
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
});

it('takes over once WordPress has added its own handler', function (): void {
    Actions\expectAdded('rest_api_init')->once()->with(Mockery::type('array'), 20);

    $lines = [];
    corsModule($lines)->register(new WordPressHooks);
});

it('swaps core\'s CORS handler for its own', function (): void {
    Functions\expect('remove_filter')->once()->with('rest_pre_serve_request', 'rest_send_cors_headers');
    Filters\expectAdded('rest_pre_serve_request')->once()->with(Mockery::type('array'), 10, 3);

    $lines = [];
    corsModule($lines)->takeOverFromCore();
});

it('sends the allowed origin and keeps every Vary line', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';
    $lines = [];

    $served = corsModule($lines)->send(false, null, restRequest());

    expect($served)->toBeFalse()
        ->and($lines)->toContain('add Vary: Origin')
        ->and($lines)->toContain('set Access-Control-Allow-Origin: https://www.example.com');
});

it('sends only Vary to an origin that is not listed', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.org';
    $lines = [];

    corsModule($lines)->send(false, null, restRequest());

    expect($lines)->toBe(['add Vary: Origin']);
});

it('answers a preflight', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://www.example.com';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'content-type';
    $lines = [];

    corsModule($lines)->send(false, null, restRequest('OPTIONS'));

    expect($lines)->toContain('set Access-Control-Allow-Headers: content-type')
        ->and(implode("\n", $lines))->toContain('Access-Control-Allow-Methods');
});

it('sends headers with PHP\'s header() by default', function (): void {
    $module = new Cors(new CorsPolicy(['https://www.example.com']));
    unset($_SERVER['HTTP_ORIGIN']);

    expect($module->send(true, null, restRequest()))->toBeTrue();
});
