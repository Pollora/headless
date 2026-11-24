<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Preview\PreviewClaims;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\PreviewLinks;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\PreviewRoutes;
use Pollora\Headless\Infrastructure\WordPress\TransientPreviewStore;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Infrastructure\WordPress\WordPressPostAccess;
use Pollora\Headless\Tests\Fixtures\FakePostAccess;
use Pollora\Headless\Tests\Fixtures\FakePreviewStore;

function wpPost(int $id = 42, string $type = 'post', string $status = 'draft'): WP_Post
{
    $post = Mockery::mock('WP_Post');
    $post->ID = $id;
    $post->post_type = $type;
    $post->post_status = $status;

    return $post;
}

function previewServiceFor(?FakePostAccess $posts = null): PreviewService
{
    return new PreviewService(new FakePreviewStore, $posts ?? new FakePostAccess, 'key', 'https://www.example.com');
}

it('hooks the editor\'s preview link', function (): void {
    Filters\expectAdded('preview_post_link')->once()->with(Mockery::type('array'), 10, 2);

    (new PreviewLinks(previewServiceFor(), 'https://www.example.com/api/preview'))->register(new WordPressHooks);
});

it('builds a preview link with a token for the post and the path of its permalink', function (): void {
    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('user_can')->justReturn(true);
    Functions\when('get_permalink')->justReturn('https://www.example.com/?p=42&x=1');
    Functions\when('get_current_blog_id')->justReturn(2);
    Functions\when('get_locale')->justReturn('fr_FR');
    Functions\when('add_query_arg')->alias(static fn (array $args, string $url): string => $url.'?'.http_build_query($args));

    $link = (new PreviewLinks(previewServiceFor(), 'https://www.example.com/api/preview'))->link('https://cms.example.com/?p=42&preview=true', wpPost());
    parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $query);
    $claims = TokenSigner::verify($query['token'], 'key', time(), PreviewTokenType::Link);

    expect($link)->toStartWith('https://www.example.com/api/preview?')
        ->and($query)->toMatchArray(['id' => '42', 'type' => 'post', 'path' => '/?p=42&x=1', 'site' => '2', 'locale' => 'fr_FR'])
        ->and([$claims->userId, $claims->postId, $claims->siteId])->toBe([7, 42, 2]);
});

it('leaves WordPress\'s link to a visitor, to someone who cannot edit, and to something that is not a post', function (): void {
    $module = new PreviewLinks(previewServiceFor(), 'https://www.example.com/api/preview');

    Functions\when('get_current_user_id')->justReturn(0);
    expect($module->link('wp-link', wpPost()))->toBe('wp-link');

    Functions\when('get_current_user_id')->justReturn(7);
    Functions\when('user_can')->justReturn(false);
    expect($module->link('wp-link', wpPost()))->toBe('wp-link')
        ->and($module->link('wp-link', null))->toBe('wp-link')
        ->and($module->link(false, 'not a post'))->toBeFalse();
});

it('remembers a transient and spends it once', function (): void {
    $transients = new ArrayObject;
    Functions\when('set_transient')->alias(function (string $key, mixed $value) use ($transients): bool {
        $transients[$key] = $value;

        return true;
    });
    Functions\when('get_transient')->alias(static fn (string $key): mixed => $transients[$key] ?? false);
    Functions\when('delete_transient')->alias(function (string $key) use ($transients): bool {
        $had = isset($transients[$key]);
        unset($transients[$key]);

        return $had;
    });

    $store = new TransientPreviewStore;
    $store->remember('abc', 300);

    expect($transients->getArrayCopy())->toBe(['headless_pv_abc' => 1])
        ->and($store->burn('abc'))->toBeTrue()
        ->and($store->burn('abc'))->toBeFalse()
        ->and($store->burn('never-issued'))->toBeFalse();
});

it('does not trust a transient another request deleted between the read and the delete', function (): void {
    Functions\when('get_transient')->justReturn(1);
    Functions\when('delete_transient')->justReturn(false);

    expect((new TransientPreviewStore)->burn('abc'))->toBeFalse();
});

it('says what can be previewed: a saved, visible post of a type with a REST route', function (): void {
    $access = new WordPressPostAccess;
    $type = Mockery::mock('WP_Post_Type');
    $type->show_in_rest = true;
    $hidden = Mockery::mock('WP_Post_Type');
    $hidden->show_in_rest = false;

    Functions\when('get_post')->alias(static fn (int $id): ?WP_Post => match ($id) {
        1 => wpPost(1),
        2 => wpPost(2, 'post', 'trash'),
        3 => wpPost(3, 'revision'),
        4 => wpPost(4, 'internal'),
        5 => wpPost(5, 'auto-draft'),
        default => null,
    });
    Functions\when('get_post_type_object')->alias(static fn (string $type_): ?WP_Post_Type => $type_ === 'internal' ? $hidden : $type);

    expect($access->isPreviewable(1))->toBeTrue()
        ->and($access->isPreviewable(2))->toBeFalse()
        ->and($access->isPreviewable(3))->toBeFalse()
        ->and($access->isPreviewable(4))->toBeFalse()
        ->and($access->isPreviewable(5))->toBeFalse()
        ->and($access->isPreviewable(99))->toBeFalse();
});

it('asks WordPress whether a user can edit a post', function (): void {
    Functions\expect('user_can')->once()->with(7, 'edit_post', 42)->andReturn(true);

    expect((new WordPressPostAccess)->canEdit(7, 42))->toBeTrue();
});

function previewRoutes(?FakePostAccess $posts = null): PreviewRoutes
{
    return new PreviewRoutes(previewServiceFor($posts), new PreviewPostResponse);
}

function paramsRequest(array $params, ?string $authorization = null): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_param')->andReturnUsing(static fn (string $key): mixed => $params[$key] ?? null);
    $request->shouldReceive('get_header')->with('authorization')->andReturn($authorization);

    return $request;
}

it('registers its routes on rest_api_init', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    previewRoutes()->register(new WordPressHooks);

    Functions\expect('register_rest_route')->twice()->with('headless/v1', Mockery::pattern('#^/preview/#'), Mockery::type('array'));
    previewRoutes()->routes();
});

it('answers an exchange without a usable token with 401', function (mixed $token): void {
    $error = previewRoutes()->exchange(paramsRequest(['token' => $token]));

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_code())->toBe('preview_token_invalid')
        ->and($error->get_error_data())->toBe(['status' => 401]);
})->with([[null], [''], ['garbage'], [['not', 'a', 'string']]]);

it('maps every preview failure to its stable code and status', function (string $kind, string $code, int $status): void {
    $service = previewServiceFor(new FakePostAccess([42], []));
    $routes = new PreviewRoutes($service, new PreviewPostResponse);
    $clock = time();
    $token = match ($kind) {
        'expired' => TokenSigner::sign(new PreviewClaims(PreviewTokenType::Link, 7, 42, 1, 'https://www.example.com', $clock - 10, 'x'), 'key'),
        'forbidden' => $service->linkToken(7, 42, 1),
        'not_found' => $service->linkToken(7, 99, 1),
        default => 'garbage',
    };

    $error = $routes->exchange(paramsRequest(['token' => $token]));

    expect([$error->get_error_code(), $error->get_error_data()['status']])->toBe([$code, $status]);
})->with([
    ['expired', 'preview_token_expired', 401],
    ['forbidden', 'preview_forbidden', 403],
    ['not_found', 'preview_not_found', 404],
    ['invalid', 'preview_token_invalid', 401],
]);

it('answers 409 to a link token that was already used', function (): void {
    $service = previewServiceFor();
    $routes = new PreviewRoutes($service, new PreviewPostResponse);
    $token = $service->linkToken(7, 42, 1);
    Functions\when('get_post')->justReturn(wpPost());
    Functions\when('get_permalink')->justReturn('https://www.example.com/?p=42');

    $service->exchange($token);
    $error = $routes->exchange(paramsRequest(['token' => $token]));

    expect([$error->get_error_code(), $error->get_error_data()['status']])->toBe(['preview_token_used', 409]);
});

it('authorizes a session for its post, and refuses it for another', function (): void {
    $service = previewServiceFor();
    $routes = new PreviewRoutes($service, new PreviewPostResponse);
    Functions\when('get_post')->justReturn(wpPost());
    Functions\when('get_permalink')->justReturn('x');
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;

    expect($routes->authorize(paramsRequest(['id' => 42], 'Bearer '.$session)))->toBeTrue();

    $other = $routes->authorize(paramsRequest(['id' => 43], 'Bearer '.$session));
    $forged = $routes->authorize(paramsRequest(['id' => 42], 'Bearer hlp.forged'));

    expect($other->get_error_code())->toBe('preview_forbidden')
        ->and($forged->get_error_code())->toBe('preview_token_invalid');
});

it('authorizes a logged-in user who can edit the post, and nobody else', function (): void {
    $routes = previewRoutes();

    Functions\when('is_user_logged_in')->justReturn(false);
    $anonymous = $routes->authorize(paramsRequest(['id' => 42]));

    Functions\when('is_user_logged_in')->justReturn(true);
    Functions\when('current_user_can')->justReturn(false);
    $cannot = $routes->authorize(paramsRequest(['id' => 42], 'Bearer hl.7.0123456789ab.'.str_repeat('A', 43)));

    Functions\when('current_user_can')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(7);

    expect($anonymous->get_error_data())->toBe(['status' => 401])
        ->and($cannot->get_error_data())->toBe(['status' => 403])
        ->and($routes->authorize(paramsRequest(['id' => 42])))->toBeTrue();
});

it('reads the preview of the post the permission check authorised', function (): void {
    Functions\when('get_post')->justReturn(null);
    $service = previewServiceFor();
    $routes = new PreviewRoutes($service, new PreviewPostResponse);
    Functions\when('get_permalink')->justReturn('x');
    Functions\when('get_post')->justReturn(wpPost());
    $session = $service->exchange($service->linkToken(7, 42, 1))->token;
    $routes->authorize(paramsRequest(['id' => 42], 'Bearer '.$session));

    Functions\when('get_post')->justReturn(null);
    $error = $routes->read(paramsRequest(['id' => 42, 'rev' => 'live']));

    expect($error)->toBeInstanceOf(WP_Error::class)->and($error->get_error_code())->toBe('preview_not_found');
});

it('previews nothing for a post that does not exist', function (): void {
    Functions\when('get_post')->justReturn(null);

    $error = (new PreviewPostResponse)->build(42, 7, 'live');

    expect($error->get_error_code())->toBe('preview_not_found')->and($error->get_error_data())->toBe(['status' => 404]);
});

it('reads the preview settings', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'preview' => ['url' => '/draft', 'link_ttl' => 5, 'session_ttl' => 10, 'secret' => ' s3cret ']], 'https://cms.example.com');
    $absolute = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'preview' => ['url' => 'https://preview.example.org/enter?x=1']], 'https://cms.example.com');
    $off = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'preview' => ['enabled' => false]], 'https://cms.example.com');
    $noFront = Settings::fromArray([], 'https://cms.example.com');

    expect($settings->previewUrl)->toBe('https://www.example.com/draft')
        ->and($settings->previewSecret)->toBe('s3cret')
        ->and($settings->previewLinkTtl)->toBe(30)
        ->and($settings->previewSessionTtl)->toBe(60)
        ->and($absolute->previewUrl)->toBe('https://preview.example.org/enter')
        ->and($absolute->previewSecret)->toBeNull()
        ->and($absolute->previewLinkTtl)->toBe(300)
        ->and($off->previewUrl)->toBeNull()
        ->and($noFront->previewUrl)->toBeNull();
});
