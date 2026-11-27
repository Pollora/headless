<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\WordPress\ChangeWatcher;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Tests\Fixtures\FakeDeliveries;
use Pollora\Headless\Tests\Fixtures\FakePending;
use Pollora\Headless\Tests\Fixtures\FakeScheduler;
use Pollora\Headless\Tests\Fixtures\FakeTransport;
use Pollora\Headless\Tests\Fixtures\RecordingLogger;

/**
 * @return array{ChangeWatcher, FakePending}
 */
function watcher(): array
{
    $pending = new FakePending;
    $revalidator = new Revalidator([new Target('next', 'https://www.example.com/hook', 'k')], $pending, new FakeDeliveries, new FakeScheduler, new FakeTransport, new RecordingLogger);

    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('get_object_taxonomies')->justReturn([]);

    return [new ChangeWatcher($revalidator, new NoLanguages, ['blogname', 'permalink_structure']), $pending];
}

function aPost(int $id = 12, string $slug = 'hello', string $type = 'post', string $status = 'publish'): WP_Post
{
    $post = Mockery::mock('WP_Post');
    $post->ID = $id;
    $post->post_name = $slug;
    $post->post_type = $type;
    $post->post_status = $status;

    return $post;
}

function restType(bool $inRest = true): WP_Post_Type
{
    $type = Mockery::mock('WP_Post_Type');
    $type->show_in_rest = $inRest;

    return $type;
}

beforeEach(function (): void {
    Functions\when('get_post_type_object')->alias(static fn (string $type): ?WP_Post_Type => match ($type) {
        'private_type' => restType(false),
        'unknown_type' => null,
        default => restType(),
    });
    Functions\when('get_permalink')->alias(static fn (WP_Post $post): string => 'https://www.example.com/'.($post->post_status === 'publish' ? $post->post_name : '?p='.$post->ID).'/');
});

it('hooks every change it watches, and commits at shutdown', function (): void {
    foreach (['transition_post_status', 'post_updated', 'before_delete_post', 'created_term', 'edited_term', 'delete_term', 'wp_create_nav_menu', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_update_nav_menu_item', 'added_option', 'updated_option', 'deleted_option', 'add_attachment', 'edit_attachment', 'delete_attachment', 'shutdown'] as $hook) {
        Actions\expectAdded($hook)->once();
    }

    [$watcher] = watcher();
    $watcher->register(new WordPressHooks);
});

it('reports a post that is published, with its tags, its address and its language', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition('publish', 'draft', aPost());
    $watcher->commit();

    $event = $pending->events[0];

    expect($event->name)->toBe(ChangeEvent::POST_PUBLISHED)
        ->and($event->tags)->toBe(['post:12', 'type:post', 'site:1'])
        ->and($event->urls)->toBe(['https://www.example.com/hello/'])
        ->and($event->post)->toBe(['id' => 12, 'type' => 'post', 'slug' => 'hello', 'old_slug' => null, 'status_from' => 'draft', 'status_to' => 'publish'])
        ->and($event->locale)->toBe('en_US');
});

it('reports an update, with the old slug and both addresses', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition('publish', 'publish', aPost(12, 'new-slug'));
    $watcher->postUpdated(12, aPost(12, 'new-slug'), aPost(12, 'old-slug'));
    $watcher->commit();

    expect($pending->events[0]->name)->toBe(ChangeEvent::POST_UPDATED)
        ->and($pending->events[0]->post['old_slug'])->toBe('old-slug')
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/new-slug/', 'https://www.example.com/old-slug/']);
});

it('reports a post that stops being public, with the address it had', function (string $to): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition($to, 'publish', aPost(12, 'hello__trashed', 'post', $to));
    $watcher->commit();

    expect($pending->events[0]->name)->toBe(ChangeEvent::POST_UNPUBLISHED)
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/hello/'])
        ->and($pending->events[0]->post['slug'])->toBe('hello');
})->with(['trash', 'draft', 'private']);

it('reports a published post that is deleted', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postDeleting(12, aPost());
    $watcher->commit();

    expect($pending->events[0]->name)->toBe(ChangeEvent::POST_DELETED)
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/hello/']);
});

it('says nothing of a change nobody could see', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition('draft', 'new', aPost(1, 'a', 'post', 'draft'));
    $watcher->postTransition('draft', 'pending', aPost(2, 'b', 'post', 'draft'));
    $watcher->postDeleting(3, aPost(3, 'c', 'post', 'draft'));
    $watcher->postDeleting(4, aPost(4, 'c', 'post', 'trash'));
    $watcher->postTransition('publish', 'draft', aPost(5, 'x', 'revision'));
    $watcher->postTransition('publish', 'draft', aPost(6, 'x', 'attachment'));
    $watcher->postTransition('publish', 'draft', aPost(7, 'x', 'private_type'));
    $watcher->postTransition('publish', 'draft', aPost(8, 'x', 'unknown_type'));
    $watcher->postTransition(5, 'draft', aPost(9));
    $watcher->postTransition('publish', 'draft', 'not a post');
    $watcher->postUpdated(99, aPost(), aPost());
    $watcher->commit();

    expect($pending->events)->toBe([]);
});

it('keeps the first and the last status when a post changes several times in one request', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition('draft', 'publish', aPost(12, 'hello', 'post', 'draft'));
    $watcher->postTransition('publish', 'draft', aPost());
    $watcher->commit();

    // published, then back to published: the front end was shown the same page, so it is an update
    expect($pending->events[0]->name)->toBe(ChangeEvent::POST_UPDATED)
        ->and($pending->events[0]->post['status_from'])->toBe('publish');
});

it('records once per request: committing twice does not repeat', function (): void {
    [$watcher, $pending] = watcher();
    $watcher->postTransition('publish', 'draft', aPost());

    $watcher->commit();
    $watcher->commit();

    expect($pending->events)->toHaveCount(1);
});

it('tags the terms of a post that are public', function (): void {
    [$watcher, $pending] = watcher();
    $taxonomy = Mockery::mock('WP_Taxonomy');
    $taxonomy->show_in_rest = true;
    $taxonomy->name = 'category';
    $hidden = Mockery::mock('WP_Taxonomy');
    $hidden->show_in_rest = false;
    $hidden->name = 'secret';
    $term = Mockery::mock('WP_Term');
    $term->term_id = 5;
    Functions\when('get_object_taxonomies')->justReturn([$taxonomy, $hidden]);
    Functions\when('get_the_terms')->justReturn([$term]);

    $watcher->postTransition('publish', 'draft', aPost());
    $watcher->commit();

    expect($pending->events[0]->tags)->toBe(['post:12', 'type:post', 'site:1', 'term:5']);
});

it('reports a term of a public taxonomy, with its page', function (): void {
    [$watcher, $pending] = watcher();
    $taxonomy = Mockery::mock('WP_Taxonomy');
    $taxonomy->show_in_rest = true;
    Functions\when('get_taxonomy')->alias(static fn (string $name): WP_Taxonomy|false => $name === 'category' ? $taxonomy : false);
    Functions\when('get_term_link')->justReturn('https://www.example.com/category/news/');

    $watcher->termChanged(5, 5, 'category');
    $watcher->termChanged(6, 6, 'nav_menu');
    $watcher->termChanged('x', 1, 'category');
    $watcher->commit();

    expect($pending->events)->toHaveCount(1)
        ->and($pending->events[0]->name)->toBe(ChangeEvent::TERM_CHANGED)
        ->and($pending->events[0]->tags)->toBe(['term:5', 'taxonomy:category', 'site:1'])
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/category/news/']);
});

it('reports a deleted term without a page', function (): void {
    [$watcher, $pending] = watcher();
    $taxonomy = Mockery::mock('WP_Taxonomy');
    $taxonomy->show_in_rest = true;
    Functions\when('get_taxonomy')->justReturn($taxonomy);
    Functions\when('get_term_link')->justReturn(new WP_Error('invalid_term', 'gone'));

    $watcher->termChanged(5, 5, 'category');
    $watcher->commit();

    expect($pending->events[0]->urls)->toBe([]);
});

it('reports a menu by its ID and its slug', function (): void {
    [$watcher, $pending] = watcher();
    $menu = Mockery::mock('WP_Term');
    $menu->slug = 'primary';
    Functions\when('wp_get_nav_menu_object')->alias(static fn (int $id): WP_Term|false => $id === 3 ? $menu : false);

    $watcher->menuChanged(3);
    $watcher->menuChanged(4);
    $watcher->menuChanged('x');
    $watcher->commit();

    expect($pending->events)->toHaveCount(2)
        ->and($pending->events[0]->tags)->toBe(['menus', 'menu:3', 'site:1', 'menu:primary'])
        ->and($pending->events[1]->tags)->toBe(['menus', 'menu:4', 'site:1']);
});

it('reports the options it was told to watch, and no other', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->optionChanged('blogname');
    $watcher->optionChanged('active_plugins');
    $watcher->optionChanged(12);
    $watcher->commit();

    expect($pending->events)->toHaveCount(1)->and($pending->events[0]->tags)->toBe(['options', 'option:blogname', 'site:1']);
});

it('reports a file of the media library', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->mediaChanged(77);
    $watcher->mediaChanged('x');
    $watcher->commit();

    expect($pending->events)->toHaveCount(1)->and($pending->events[0]->tags)->toBe(['media', 'media:77', 'site:1']);
});

it('uses the language of the post when a multilingual plugin tells it', function (): void {
    $pending = new FakePending;
    $revalidator = new Revalidator([new Target('next', 'https://www.example.com/hook', 'k')], $pending, new FakeDeliveries, new FakeScheduler, new FakeTransport, new RecordingLogger);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('get_object_taxonomies')->justReturn([]);
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn(true);
    $languages->shouldReceive('ofPost')->with(12)->andReturn('fr');
    $languages->shouldReceive('ofPost')->with(13)->andReturn(null);
    $watcher = new ChangeWatcher($revalidator, $languages, []);

    $watcher->postTransition('publish', 'draft', aPost(12));
    $watcher->postTransition('publish', 'draft', aPost(13, 'other'));
    $watcher->commit();

    expect($pending->events[0]->locale)->toBe('fr')->and($pending->events[1]->locale)->toBe('en_US');
});

it('says nothing of a post published and taken back within one request', function (): void {
    [$watcher, $pending] = watcher();

    $watcher->postTransition('publish', 'draft', aPost());
    $watcher->postTransition('draft', 'publish', aPost(status: 'draft'));
    $watcher->commit();

    expect($pending->drain())->toBe([]);
});
