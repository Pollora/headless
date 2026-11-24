<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

it('registers the field on the types that have an editor', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    $module = new BlocksField;
    $module->register(new WordPressHooks);

    Functions\when('get_post_types')->justReturn(['post', 'page', 'event']);
    Functions\when('post_type_supports')->alias(static fn (string $type, string $feature): bool => $type !== 'event' && $feature === 'editor');
    Functions\expect('register_rest_field')->twice()->with(Mockery::anyOf('post', 'page'), 'headless_blocks', Mockery::type('array'));

    $module->fields();
});

it('gives no blocks unless the request names the field', function (): void {
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_param')->with('_fields')->andReturn('id,title');
    Functions\expect('get_post')->never();

    expect((new BlocksField)->blocks(['id' => 12], 'headless_blocks', $request))->toBeNull();
});

it('gives no blocks for a post that is gone or protected by a password', function (): void {
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_param')->with('_fields')->andReturn('headless_blocks');
    $post = Mockery::mock('WP_Post');
    Functions\when('get_post')->alias(static fn (int $id): ?WP_Post => $id === 12 ? $post : null);
    Functions\when('post_password_required')->justReturn(true);

    expect((new BlocksField)->blocks(['id' => 99], 'headless_blocks', $request))->toBeNull()
        ->and((new BlocksField)->blocks(['id' => 12], 'headless_blocks', $request))->toBeNull();
});

it('reads the blocks setting', function (): void {
    expect(Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com')->blocks)->toBeTrue()
        ->and(Settings::fromArray(['frontend_url' => 'https://www.example.com', 'blocks' => ['enabled' => false]], 'https://cms.example.com')->blocks)->toBeFalse()
        ->and(Settings::fromArray([], 'https://cms.example.com')->blocks)->toBeFalse();
});
