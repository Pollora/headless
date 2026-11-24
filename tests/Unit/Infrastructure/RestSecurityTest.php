<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Infrastructure\WordPress\RestSecurity;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function routeRequest(string $route): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_route')->andReturn($route);

    return $request;
}

it('hooks the users guard and the sitemap provider when users are restricted', function (): void {
    Filters\expectAdded('rest_pre_dispatch')->once();
    Filters\expectAdded('wp_sitemaps_add_provider')->once();

    (new RestSecurity(true, false))->register(new WordPressHooks);
});

it('hooks nothing when nothing is switched on', function (): void {
    Filters\expectAdded('rest_pre_dispatch')->never();

    (new RestSecurity(false, false))->register(new WordPressHooks);
});

it('turns the file editors off once', function (): void {
    // Constants last for the whole process: another test may have defined it already, which is the case this covers too
    (new RestSecurity(false, true))->register(new WordPressHooks);
    (new RestSecurity(false, true))->register(new WordPressHooks);

    expect(DISALLOW_FILE_EDIT)->toBeTrue();
});

it('refuses the users routes to a visitor who is not logged in', function (string $route): void {
    Functions\when('is_user_logged_in')->justReturn(false);

    $error = (new RestSecurity(true, false))->guardUsers(null, null, routeRequest($route));

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_code())->toBe('rest_user_cannot_view')
        ->and($error->get_error_data())->toBe(['status' => 401]);
})->with(['/wp/v2/users', '/wp/v2/users/1', '/wp/v2/users/me']);

it('lets a logged-in user, another route and an earlier answer through', function (): void {
    $guard = new RestSecurity(true, false);

    Functions\when('is_user_logged_in')->justReturn(true);
    expect($guard->guardUsers(null, null, routeRequest('/wp/v2/users')))->toBeNull();

    Functions\when('is_user_logged_in')->justReturn(false);
    expect($guard->guardUsers(null, null, routeRequest('/wp/v2/posts')))->toBeNull()
        ->and($guard->guardUsers(null, null, routeRequest('/wp/v2/usersx')))->toBeNull()
        ->and($guard->guardUsers('earlier', null, routeRequest('/wp/v2/users')))->toBe('earlier');
});

it('leaves the users provider out of the core sitemap', function (): void {
    $guard = new RestSecurity(true, false);

    expect($guard->withoutUsersProvider('provider', 'users'))->toBeFalse()
        ->and($guard->withoutUsersProvider('provider', 'posts'))->toBe('provider');
});
