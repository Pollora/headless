<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RankMathRedirects;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\PublicSurface;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

/** Notes that PHP woke an object of this class up. */
final class ExposureWokenObject
{
    public static int $woken = 0;

    public function __wakeup(): void
    {
        self::$woken++;
    }
}

it('wakes no object held in the sources of a Rank Math rule', function (): void {
    ExposureWokenObject::$woken = 0;
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('prepare')->andReturn('sql');
    $wpdb->shouldReceive('get_var')->andReturn('wp_rank_math_redirections');
    $wpdb->shouldReceive('get_results')->andReturn([(object) ['sources' => serialize([new ExposureWokenObject]), 'url_to' => '/new', 'header_code' => '301']]);
    $GLOBALS['wpdb'] = $wpdb;

    expect((new RankMathRedirects)->redirects())->toBe([])
        ->and(ExposureWokenObject::$woken)->toBe(0);
});

it('hides a file attached to a post the visitor may not read, the first post of the site included', function (): void {
    $file = new WP_Post(['ID' => 9, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => 1]);
    $parent = new WP_Post(['ID' => 1, 'post_status' => 'private']);
    Functions\when('get_post')->alias(static fn (int $id): WP_Post => $id === 9 ? $file : $parent);
    Functions\when('is_post_publicly_viewable')->justReturn(false);
    Functions\expect('current_user_can')->once()->with('read_post', 1)->andReturn(false);

    expect((new NormalizedMedia)->for(9))->toBeNull();
});

it('does not read a stored rule without a pattern flag as a pattern', function (): void {
    $rule = Redirect::tryFromArray(['from' => '/a.b', 'to' => '/c']);

    expect($rule?->regex)->toBeFalse()->and($rule?->resolve('/aXb'))->toBeNull();
});

it('matches a pattern that holds the character the package delimits patterns with', function (): void {
    expect((new Redirect('/a#(\d+)', '/b/$1', 301, true))->resolve('/a#7'))->toBe('/b/7');
});

it('does not autoload the rules after one is removed', function (): void {
    Functions\when('get_option')->justReturn(['/a' => ['from' => '/a', 'to' => '/b']]);
    Functions\expect('update_option')->once()->with('headless_redirects', Mockery::type('array'), false);

    expect((new OptionRedirectStore)->remove('/a'))->toBeTrue();
});

it('keeps the newest rules of an editor up to the limit, each under its own path', function (): void {
    $stored = [];

    foreach (range(1, OptionRedirectStore::LIMIT) as $i) {
        $stored['/old-'.$i] = ['from' => '/old-'.$i, 'to' => '/new'];
    }

    Functions\when('get_option')->justReturn($stored);
    Functions\expect('update_option')->once()->with('headless_redirects', Mockery::on(static fn (array $all): bool => count($all) === OptionRedirectStore::LIMIT
        && array_key_first($all) === '/old-2'
        && array_key_last($all) === '/last'), false);

    (new OptionRedirectStore)->save(new Redirect('/last', '/new'));
});

it('refuses a feed before WordPress prints it', function (): void {
    foreach (['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'] as $feed) {
        Actions\expectAdded($feed)->once()->with(Mockery::type('array'), 1);
    }

    (new PublicSurface(true, false, false))->register(new WordPressHooks);
});
