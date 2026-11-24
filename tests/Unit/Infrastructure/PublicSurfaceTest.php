<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Infrastructure\WordPress\PublicSurface;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

it('hooks every feed, the sitemap switch and the noindex answers', function (): void {
    foreach (['do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom'] as $feed) {
        Brain\Monkey\Actions\expectAdded($feed)->once();
    }

    Filters\expectAdded('wp_sitemaps_enabled')->once()->with('__return_false');
    Filters\expectAdded('wp_headers')->once();
    Filters\expectAdded('robots_txt')->once();
    Filters\expectAdded('wp_robots')->once();

    (new PublicSurface(true, true, true))->register(new WordPressHooks);
});

it('hooks nothing when every switch is off', function (): void {
    Filters\expectAdded('wp_sitemaps_enabled')->never();
    Filters\expectAdded('wp_headers')->never();
    Brain\Monkey\Actions\expectAdded('do_feed')->never();

    (new PublicSurface(false, false, false))->register(new WordPressHooks);
});

it('answers a feed with a 404', function (): void {
    Functions\expect('wp_die')->once()->with('', '', ['response' => 404]);

    (new PublicSurface(true, false, false))->refuseFeed();
});

it('adds the noindex header, the robots.txt body and the robots meta', function (): void {
    $surface = new PublicSurface(false, false, true);

    expect($surface->withNoindexHeader(['Link' => 'x']))->toBe(['Link' => 'x', 'X-Robots-Tag' => 'noindex, nofollow'])
        ->and($surface->robotsTxt())->toBe("User-agent: *\nDisallow: /\n")
        ->and($surface->robots(['max-image-preview' => 'large']))->toBe(['max-image-preview' => 'large', 'noindex' => true, 'nofollow' => true]);
});
