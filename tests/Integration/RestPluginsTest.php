<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Plugin\PluginFilter;
use Pollora\Headless\Infrastructure\WordPress\RestPlugins;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The plugin filter against the real list of active plugins: only the plugins listed stay on a REST request.
 */
afterEach(function (): void {
    remove_all_filters('option_active_plugins');
});

it('keeps only the listed plugins of the real active list on a REST request', function (): void {
    $all = get_option('active_plugins');
    (new RestPlugins(new PluginFilter(['wordpress-seo']), true))->register(new WordPressHooks);

    $kept = get_option('active_plugins');

    expect($all)->toContain('polylang/polylang.php')
        ->and($kept)->toContain('wordpress-seo/wp-seo.php')
        ->and($kept)->not->toContain('polylang/polylang.php')
        ->and(count($kept))->toBeLessThan(count($all));
});

it('leaves the list alone on any other request', function (): void {
    $all = get_option('active_plugins');
    (new RestPlugins(new PluginFilter(['wordpress-seo']), false))->register(new WordPressHooks);

    expect(get_option('active_plugins'))->toBe($all);
});
