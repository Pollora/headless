<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\AllowList;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\RequestTarget;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Language\LanguageUrls;
use Pollora\Headless\Domain\Manifest\MenuTree;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Infrastructure\Exceptions\ThemeInstallException;
use Pollora\Headless\Infrastructure\Form\ContactForm7Provider;
use Pollora\Headless\Infrastructure\Revalidation\ActionSchedulerScheduler;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;

require_once dirname(__DIR__, 2).'/Stubs/contact-form-7.php';

/*
 * The blocks and files of a post, and who may see them; forms; the WP-Cron fallback.
 */

afterEach(function (): void {
    unset($GLOBALS['post']);
    WP_Block_Type_Registry::$types = [];
    WPCF7_ContactForm::$forms = [];
});

it('gives the blocks of the post asked for, with its own post current and the defaults of each type', function (): void {
    WP_Block_Type_Registry::$types = [
        'acme/list' => new WP_Block_Type(['count' => ['type' => 'number', 'default' => 3], 'order' => ['default' => 'asc'], 'title' => ['type' => 'string']], true),
    ];
    $post = new WP_Post(['ID' => 12, 'post_content' => 'content']);
    $GLOBALS['post'] = $before = new WP_Post(['ID' => 1]);
    $current = new ArrayObject;
    Functions\when('get_post')->justReturn($post);
    Functions\when('post_password_required')->justReturn(false);
    Functions\when('setup_postdata')->alias(static function (WP_Post $post) use ($current): bool {
        $current->append($post->ID);

        return true;
    });
    Functions\when('parse_blocks')->justReturn([['blockName' => 'acme/list', 'attrs' => ['order' => 'desc'], 'innerHTML' => '', 'innerBlocks' => [], 'innerContent' => []]]);
    Functions\when('render_block')->alias(static fn (): string => 'rendered with post '.$GLOBALS['post']->ID);
    $request = new WP_REST_Request;
    $request->set_param('_fields', 'id,headless_blocks');

    $blocks = (new BlocksField)->blocks(['id' => 12], 'headless_blocks', $request);

    expect($blocks[0]['attributes'])->toBe(['order' => 'desc', 'count' => 3])
        ->and($blocks[0]['rendered'])->toBe('rendered with post 12')
        ->and($GLOBALS['post'])->toBe($before)
        ->and($current->getArrayCopy())->toBe([12, 1]);
});

it('puts the current post back when a block fails to render', function (): void {
    $GLOBALS['post'] = $before = new WP_Post(['ID' => 1]);
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('parse_blocks')->justReturn([['blockName' => 'acme/broken', 'innerHTML' => 'x']]);
    Functions\when('render_block')->alias(static fn () => throw new RuntimeException('block'));

    expect(fn () => BlocksField::forContent('x', new WP_Post(['ID' => 12])))->toThrow(RuntimeException::class)
        ->and($GLOBALS['post'])->toBe($before);
});

/**
 * @param  array<int, WP_Post>  $posts
 * @param  list<int>  $readable  the posts the current user may read
 */
function mediaLibrary(array $posts, array $readable = [], bool $parentIsPublic = false): void
{
    Functions\when('get_post')->alias(static fn (int $id): ?WP_Post => $posts[$id] ?? null);
    Functions\when('current_user_can')->alias(static fn (string $capability, int $id): bool => $capability === 'read_post' && in_array($id, $readable, true));
    Functions\when('is_post_publicly_viewable')->justReturn($parentIsPublic);
    Functions\when('get_post_mime_type')->justReturn('application/pdf');
    Functions\when('wp_get_attachment_url')->justReturn('https://cms.example.com/u/a.pdf');
    Functions\when('wp_get_attachment_metadata')->justReturn(['filesize' => 2048]);
    Functions\when('get_post_meta')->justReturn('');
}

function attachment(int $parent = 0, string $status = 'inherit'): WP_Post
{
    return new WP_Post(['ID' => 9, 'post_type' => 'attachment', 'post_status' => $status, 'post_parent' => $parent]);
}

it('gives a file only to who may see it', function (WP_Post $file, array $readable, bool $parentIsPublic, bool $seen): void {
    mediaLibrary([9 => $file, 4 => new WP_Post(['ID' => 4, 'post_status' => 'draft'])], $readable, $parentIsPublic);

    expect((new NormalizedMedia)->for(9) !== null)->toBe($seen);
})->with([
    'a file of the library' => [attachment(), [], false, true],
    'a file whose post is gone' => [attachment(77), [], false, true],
    'a file of a public post' => [attachment(4), [], true, true],
    'a file of a draft, to a visitor' => [attachment(4), [], false, false],
    'a file of a draft, to who may read it' => [attachment(4), [4], false, true],
    'a private file, to a visitor' => [attachment(0, 'private'), [], true, false],
    'a private file of a public post' => [attachment(4, 'private'), [4], true, false],
    'a private file, to who may read it' => [attachment(0, 'private'), [9], false, true],
    'a file in the media trash, to a visitor' => [attachment(0, 'trash'), [], false, false],
    'a trashed file of a public post, to a visitor' => [attachment(4, 'trash'), [4], true, false],
    'a file in the media trash, to who may read it' => [attachment(0, 'trash'), [9], false, true],
]);

it('gives nothing for an ID that is not a file', function (): void {
    mediaLibrary([4 => new WP_Post(['ID' => 4])]);

    expect((new NormalizedMedia)->for(4))->toBeNull()
        ->and((new NormalizedMedia)->for(5))->toBeNull();
});

it('adds the full size of an image', function (): void {
    mediaLibrary([9 => attachment()]);
    Functions\when('get_post_mime_type')->justReturn('image/png');
    Functions\when('wp_get_attachment_metadata')->justReturn(['width' => 640, 'height' => 480]);
    Functions\when('wp_get_attachment_image_srcset')->justReturn('a 1x');
    Functions\when('apply_filters')->returnArg(2);

    $media = (new NormalizedMedia)->for(9);

    expect($media['sizes'])->toBe(['full' => ['url' => 'https://cms.example.com/u/a.pdf', 'width' => 640, 'height' => 480]])
        ->and([$media['width'], $media['height'], $media['srcset']])->toBe([640, 480, 'a 1x']);
});

it('finds one form of Contact Form 7 by its number only, and gives options to the choice fields only', function (): void {
    Functions\when('rest_url')->returnArg();
    WPCF7_ContactForm::$forms = [3 => new WPCF7_ContactForm(3, 'Contact', 'fr_FR', [
        new WPCF7_FormTag('text', 'name', ['a default'], [], [], true),
        new WPCF7_FormTag('checkbox', 'one', ['x'], ['X'], ['exclusive']),
        new WPCF7_FormTag('select', 'many', ['y'], [], ['multiple']),
        new WPCF7_FormTag('submit', 'send'),
    ])];
    $provider = new ContactForm7Provider;

    $form = $provider->find('3')->toArray();

    expect($form['id'])->toBe('3')
        ->and($form['title'])->toBe('Contact')
        ->and($form['submit']['url'])->toBe('contact-form-7/v1/contact-forms/3/feedback')
        ->and((array) $form['submit']['extra'])->toBe(['_wpcf7' => '3', '_wpcf7_locale' => 'fr_FR', '_wpcf7_unit_tag' => 'wpcf7-f3-o1'])
        ->and(array_map(static fn (array $field): array => [$field['name'], $field['required'], $field['multiple'], $field['options'] ?? []], $form['fields']))->toBe([
            ['name', true, false, []],
            ['one', false, false, [['value' => 'x', 'label' => 'X']]],
            ['many', false, true, [['value' => 'y', 'label' => 'y']]],
        ])
        ->and($provider->find('4'))->toBeNull()
        ->and($provider->find('3abc'))->toBeNull()
        ->and($provider->find(''))->toBeNull();
});

it('falls back to WP-Cron before Action Scheduler is ready', function (): void {
    Functions\when('did_action')->justReturn(0);
    Functions\when('wp_next_scheduled')->justReturn(false);
    $events = new ArrayObject;
    Functions\when('wp_schedule_single_event')->alias(static function (int $time, string $hook, array $args = []) use ($events): bool {
        $events->append([$time - time() >= 29, $hook, $args]);

        return true;
    });

    $scheduler = new ActionSchedulerScheduler;
    $scheduler->scheduleFlush(30);
    $scheduler->scheduleFlush(30);
    $scheduler->scheduleRetry('front', 'abc', 2, 30);

    expect($events->getArrayCopy())->toBe([[true, 'pollora/headless/flush', []], [true, 'pollora/headless/retry', ['front', 'abc', 2]]]);
});

it('leaves the flush WP-Cron already holds', function (): void {
    Functions\when('did_action')->justReturn(0);
    Functions\when('wp_next_scheduled')->justReturn(123);
    Functions\expect('wp_schedule_single_event')->never();

    (new ActionSchedulerScheduler)->scheduleFlush(30);
});

it('says when the directory of the theme cannot be created', function (): void {
    $root = sys_get_temp_dir().'/headless-'.bin2hex(random_bytes(6));
    mkdir($root);
    touch($root.'/headless');
    set_error_handler(static fn (): bool => true);

    try {
        (new StubTheme($root, dirname(__DIR__, 3).'/resources/theme'))->install();
    } finally {
        restore_error_handler();
    }
})->throws(ThemeInstallException::class, '/headless": check the permissions');

it('moves the addresses of structured data and leaves its objects', function (): void {
    $empty = new stdClass;
    $seo = new Seo('x', jsonLd: [['@id' => 'https://cms.example.com/#a', 'about' => $empty]]);

    $moved = $seo->withUrls(static fn (string $url): string => str_replace('cms', 'www', $url));

    expect($moved->jsonLd)->toBe([['@id' => 'https://www.example.com/#a', 'about' => $empty]]);
});

it('does not loop on two menu items with one ID', function (): void {
    $item = static fn (int $id, int $parent, int $order): array => ['id' => $id, 'parent' => $parent, 'order' => $order];

    $tree = MenuTree::build([$item(1, 0, 1), $item(2, 1, 2), $item(1, 2, 3)]);

    expect($tree)->toBe([['id' => 1, 'children' => [['id' => 2, 'children' => []]]]]);
});

it('never lets "/" open the whole front', function (): void {
    $list = new AllowList(['/', '/open']);

    expect($list->allows(RequestTarget::fromUri('/blog', 'GET')))->toBeFalse()
        ->and($list->allows(RequestTarget::fromUri('/open/page', 'GET')))->toBeTrue();
});

it('leaves an address that is not HTTP as it is', function (): void {
    $mapper = new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'));

    expect($mapper->toFront('ftp://cms.example.com/file'))->toBe('ftp://cms.example.com/file');
});

it('takes no language from an address with no host', function (): void {
    $backend = Origin::fromUrl('https://cms.example.com');
    $front = Origin::fromUrl('https://www.example.com');
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn(true);
    $languages->shouldReceive('all')->never();

    expect((new LanguageUrls(new UrlMapper($backend, $front), $backend, $front, [], $languages))->toFront('/about'))->toBe('/about');
});

it('asks no language of a plugin that is not active', function (): void {
    $backend = Origin::fromUrl('https://cms.example.com');
    $front = Origin::fromUrl('https://www.example.com');
    $languages = Mockery::mock(Languages::class);
    $languages->shouldReceive('isActive')->andReturn(false);
    $languages->shouldReceive('all')->never();

    expect((new LanguageUrls(new UrlMapper($backend, $front), $backend, $front, [], $languages))->toFront('https://cms.example.com/about'))->toBe('https://www.example.com/about');
});
