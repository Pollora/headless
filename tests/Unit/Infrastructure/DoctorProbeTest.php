<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectSource;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\WordPress\DoctorProbe;

use function Pollora\Headless\Tests\Fixtures\isolated;

/*
 * What the doctor asks the running site. Once a test has declared get_graphql_setting(), the function exists for
 * the rest of the process: every test here declares it, and the site without WPGraphQL is checked in its own process.
 */

/**
 * @param  ArrayObject<int, int>  $users  every user the probe switched to
 * @param  ArrayObject<int, string>  $routes  every route it tried
 */
function probedSite(ArrayObject $users, ArrayObject $routes): void
{
    Functions\when('get_current_user_id')->justReturn(5);
    Functions\when('wp_set_current_user')->alias(static function (int $id) use ($users): void {
        $users->append($id);
    });
    Functions\when('rest_do_request')->alias(static function (WP_REST_Request $request) use ($routes): WP_REST_Response {
        $routes->append($request->get_method().' '.$request->get_route());

        return new WP_REST_Response([], $request->get_route() === '/wp/v2/users' ? 401 : 200);
    });
    Functions\when('get_option')->justReturn('/%postname%/');
    Functions\when('wp_get_theme')->justReturn(new class
    {
        public function exists(): bool
        {
            return true;
        }
    });
    Functions\when('did_action')->justReturn(0);
}

function probe(): DoctorProbe
{
    $source = new class implements RedirectSource
    {
        public function redirects(): array
        {
            return [new Redirect('/old', '/new')];
        }
    };

    return new DoctorProbe(new RedirectCatalog([$source]));
}

it('tries the REST API as a visitor, then gives the user back', function (): void {
    probedSite($users = new ArrayObject, $routes = new ArrayObject);
    Functions\when('get_graphql_setting')->justReturn('off');

    $facts = probe()->facts();

    expect($routes->getArrayCopy())->toBe(['GET /wp/v2/users', 'GET /wp/v2/posts'])
        ->and($users->getArrayCopy())->toBe([0, 5, 0, 5])
        ->and($facts->usersStatus)->toBe(401)
        ->and($facts->postsStatus)->toBe(200)
        ->and($facts->permalinkStructure)->toBe('/%postname%/')
        ->and($facts->themeIsValid)->toBeTrue()
        ->and($facts->actionSchedulerIsReady)->toBeFalse()
        ->and($facts->seoPlugin)->toBeNull()
        ->and($facts->redirects->all()[0]->from)->toBe('/old');
});

it('knows nothing of a route that fails, and still gives the user back', function (): void {
    probedSite($users = new ArrayObject, new ArrayObject);
    Functions\when('get_graphql_setting')->justReturn('off');
    Functions\when('rest_do_request')->alias(static fn () => throw new RuntimeException('broken endpoint'));

    $facts = probe()->facts();

    expect($facts->usersStatus)->toBeNull()
        ->and($facts->postsStatus)->toBeNull()
        ->and($users->getArrayCopy())->toBe([0, 5, 0, 5]);
});

it('says whether a visitor may introspect the GraphQL schema', function (string $setting, bool $open): void {
    probedSite(new ArrayObject, new ArrayObject);
    Functions\expect('get_graphql_setting')->once()->with('public_introspection_enabled', 'off')->andReturn($setting);

    $facts = probe()->facts();

    expect($facts->graphqlActive)->toBeTrue()->and($facts->graphqlIntrospection)->toBe($open);
})->with([['on', true], ['off', false]]);

it('says nothing of GraphQL on a site without WPGraphQL', function (): void {
    $output = isolated(<<<'PHP'
        function get_current_user_id(): int { return 0; }
        function wp_set_current_user(int $id): void {}
        function rest_do_request(object $request): never { throw new RuntimeException('no REST here'); }
        function get_option(string $name): string { return ''; }
        function wp_get_theme(): object { return new class { public function exists(): bool { return false; } }; }
        class WP_REST_Request { public function __construct(string $method, string $route) {} }
        $facts = (new Pollora\Headless\Infrastructure\WordPress\DoctorProbe(new Pollora\Headless\Infrastructure\Redirect\RedirectCatalog([])))->facts();
        echo json_encode([$facts->graphqlActive, $facts->graphqlIntrospection, $facts->actionSchedulerIsReady, $facts->themeIsValid]);
        PHP);

    expect($output)->toBe('[false,null,false,false]');
});
