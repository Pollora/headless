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

/*
 * Which changes a front end hears about, and what it is told.
 */

/** @return array{ChangeWatcher, FakePending} */
function reportingWatcher(?Languages $languages = null, array $options = ['blogname']): array
{
    $pending = new FakePending;
    $revalidator = new Revalidator([new Target('next', 'https://www.example.com/hook', 'k')], $pending, new FakeDeliveries, new FakeScheduler, new FakeTransport, new RecordingLogger);
    Functions\when('get_current_blog_id')->justReturn(4);
    Functions\when('get_locale')->justReturn('en_US');
    Functions\when('get_object_taxonomies')->justReturn([]);
    Functions\when('get_post_type_object')->alias(static function (string $name): ?WP_Post_Type {
        if ($name === 'unknown') {
            return null;
        }

        $type = Mockery::mock('WP_Post_Type');
        $type->show_in_rest = $name !== 'hidden';

        return $type;
    });
    Functions\when('get_permalink')->alias(static fn (WP_Post $post): string => 'https://www.example.com/'.$post->post_name.'/');

    return [new ChangeWatcher($revalidator, $languages ?? new NoLanguages, $options), $pending];
}

function reportingPost(array $fields = []): WP_Post
{
    return new WP_Post(array_merge(['ID' => 12, 'post_name' => 'hello', 'post_type' => 'post', 'post_status' => 'publish'], $fields));
}

/** A multilingual site whose post 12 is French and whose other posts have no language. */
function reportingLanguages(bool $active = true): Languages
{
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn($active);
    $languages->shouldReceive('ofPost')->andReturnUsing(static fn (int $id): ?string => $id === 12 ? 'fr' : null);

    return $languages;
}

it('watches the options a front end depends on', function (): void {
    expect(ChangeWatcher::defaultOptions())->toBe([
        'blogname', 'blogdescription', 'home', 'siteurl', 'page_on_front', 'page_for_posts', 'show_on_front', 'permalink_structure',
        'category_base', 'tag_base', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'gmt_offset', 'WPLANG',
        'site_icon', 'sidebars_widgets', 'headless_redirects',
    ]);
});

it('takes from each hook the arguments it reads, and commits late at shutdown', function (): void {
    Actions\expectAdded('transition_post_status')->once()->with(Mockery::type('array'), 10, 3);
    Actions\expectAdded('post_updated')->once()->with(Mockery::type('array'), 10, 3);
    Actions\expectAdded('before_delete_post')->once()->with(Mockery::type('array'), 10, 2);

    foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
        Actions\expectAdded($hook)->once()->with(Mockery::type('array'), 10, 3);
    }

    Actions\expectAdded('shutdown')->once()->with(Mockery::type('array'), 20);

    [$watcher] = reportingWatcher();
    $watcher->register(new WordPressHooks);
});

it('reports one event from the first state to the last, keeping the slug before the first change', function (): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postTransition('publish', 'draft', reportingPost());
    $watcher->postUpdated(12, reportingPost(), reportingPost(['post_name' => 'earlier']));
    $watcher->postTransition('publish', 'publish', reportingPost());
    $watcher->commit();

    expect($pending->events)->toHaveCount(1)
        ->and($pending->events[0]->name)->toBe(ChangeEvent::POST_PUBLISHED)
        ->and($pending->events[0]->post['old_slug'])->toBe('earlier')
        ->and($pending->events[0]->post['status_from'])->toBe('draft');
});

it('ignores a former state that is not a post', function (): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postTransition('publish', 'publish', reportingPost());
    $watcher->postUpdated(12, reportingPost(), 'not a post');
    $watcher->commit();

    expect($pending->events[0]->post['old_slug'])->toBeNull();
});

it('gives no old slug when the slug did not change', function (): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postTransition('publish', 'publish', reportingPost());
    $watcher->postUpdated(12, reportingPost(), reportingPost());
    $watcher->commit();

    expect($pending->events[0]->post['old_slug'])->toBeNull()
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/hello/']);
});

it('says nothing of the deletion of what was not public', function (mixed $id, mixed $post): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postDeleting($id, $post);
    $watcher->commit();

    expect($pending->events)->toBe([]);
})->with([
    'a number given as text' => ['12', reportingPost()],
    'not a post' => [12, (object) ['post_status' => 'publish', 'post_type' => 'post']],
    'a draft' => [12, reportingPost(['post_status' => 'draft'])],
    'a type the REST API does not serve' => [12, reportingPost(['post_type' => 'hidden'])],
]);

it('reports a deleted post with where it was', function (): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postDeleting(12, reportingPost());
    $watcher->commit();

    expect($pending->events[0]->name)->toBe(ChangeEvent::POST_DELETED)
        ->and($pending->events[0]->urls)->toBe(['https://www.example.com/hello/'])
        ->and($pending->events[0]->post)->toBe(['id' => 12, 'type' => 'post', 'slug' => 'hello', 'old_slug' => null, 'status_from' => 'publish', 'status_to' => 'deleted']);
});

it('never reports the technical post types', function (string $type): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->postTransition('publish', 'draft', reportingPost(['post_type' => $type]));
    $watcher->commit();

    expect($pending->events)->toBe([]);
})->with(['revision', 'nav_menu_item', 'auto-draft', 'customize_changeset', 'attachment', 'hidden', 'unknown']);

it('tags a post with its terms of the taxonomies the REST API serves, past the ones it does not', function (): void {
    [$watcher, $pending] = reportingWatcher();
    Functions\when('get_object_taxonomies')->justReturn([
        (object) ['name' => 'private', 'show_in_rest' => false],
        (object) ['name' => 'category', 'show_in_rest' => true],
    ]);
    Functions\when('get_the_terms')->alias(static fn (WP_Post $post, string $taxonomy): array => [(object) ['term_id' => $taxonomy === 'category' ? 3 : 99]]);

    $watcher->postTransition('publish', 'draft', reportingPost());
    $watcher->commit();

    expect($pending->events[0]->tags)->toBe(['post:12', 'type:post', 'site:4', 'term:3']);
});

it('lists no address for a post that has none', function (): void {
    [$watcher, $pending] = reportingWatcher();
    Functions\when('get_permalink')->justReturn(false);

    $watcher->postTransition('publish', 'publish', reportingPost());
    $watcher->commit();

    expect($pending->events[0]->urls)->toBe([]);
});

it('keeps the addresses a list when the new one is missing', function (): void {
    [$watcher, $pending] = reportingWatcher();
    Functions\when('get_permalink')->alias(static fn (WP_Post $post): string|false => $post->post_name === 'hello' ? false : 'https://www.example.com/earlier/');

    $watcher->postTransition('publish', 'publish', reportingPost());
    $watcher->postUpdated(12, reportingPost(), reportingPost(['post_name' => 'earlier']));
    $watcher->commit();

    expect($pending->events[0]->urls)->toBe(['https://www.example.com/earlier/']);
});

it('says nothing of a term of a taxonomy the REST API does not serve', function (bool $exists): void {
    [$watcher, $pending] = reportingWatcher();
    $taxonomy = false;

    if ($exists) {
        $taxonomy = Mockery::mock('WP_Taxonomy');
        $taxonomy->show_in_rest = false;
    }

    Functions\when('get_taxonomy')->justReturn($taxonomy);
    Functions\when('get_term_link')->justReturn('https://www.example.com/t/');

    $watcher->termChanged(3, 3, 'secret');
    $watcher->commit();

    expect($pending->events)->toBe([]);
})->with([true, false]);

it('gives a post its own language on a multilingual site, and the site\'s locale otherwise', function (bool $active, int $id, string $locale): void {
    [$watcher, $pending] = reportingWatcher(reportingLanguages($active));

    $watcher->postTransition('publish', 'draft', reportingPost(['ID' => $id]));
    $watcher->commit();

    expect($pending->events[0]->locale)->toBe($locale);
})->with([[true, 12, 'fr'], [true, 13, 'en_US'], [false, 12, 'en_US']]);

it('asks no language for what is not a post', function (): void {
    [$watcher, $pending] = reportingWatcher(reportingLanguages());

    $watcher->optionChanged('blogname');
    $watcher->commit();

    expect($pending->events[0]->locale)->toBe('en_US')->and($pending->events[0]->tags)->toBe(['options', 'option:blogname', 'site:4']);
});

it('reports each gathered change once', function (): void {
    [$watcher, $pending] = reportingWatcher();

    $watcher->mediaChanged(5);
    $watcher->commit();
    $watcher->commit();

    expect($pending->events)->toHaveCount(1)
        ->and($pending->events[0]->tags)->toBe(['media', 'media:5', 'site:4'])
        ->and($pending->events[0]->siteId)->toBe(4);
});
