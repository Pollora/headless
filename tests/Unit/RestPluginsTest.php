<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Pollora\Headless\Domain\Plugin\PluginFilter;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\WordPress\RestPlugins;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

it('keeps the plugins listed by file or by folder', function (): void {
    $filter = new PluginFilter(['advanced-custom-fields', 'seo/seo.php']);

    expect($filter->filter(['advanced-custom-fields/acf.php', 'seo/seo.php', 'seo/other.php', 'hello.php', 'cache/cache.php', 42]))->toBe(['advanced-custom-fields/acf.php', 'seo/seo.php'])
        ->and($filter->keeps('hello.php'))->toBeFalse()
        ->and((new PluginFilter(['hello.php']))->keeps('hello.php'))->toBeTrue()
        ->and((new PluginFilter([]))->filter(['a/a.php']))->toBe([]);
});

it('tells a REST request from its address', function (): void {
    expect(PluginFilter::isRestRequest('/wp-json/wp/v2/posts?per_page=2'))->toBeTrue()
        ->and(PluginFilter::isRestRequest('/blog/wp-json/'))->toBeTrue()
        ->and(PluginFilter::isRestRequest('/wp-json'))->toBeTrue()
        ->and(PluginFilter::isRestRequest('/index.php?rest_route=/wp/v2/posts', true))->toBeTrue()
        ->and(PluginFilter::isRestRequest('/wp-admin/admin-ajax.php'))->toBeFalse()
        ->and(PluginFilter::isRestRequest('/my-wp-json-page/'))->toBeFalse()
        ->and(PluginFilter::isRestRequest('/'))->toBeFalse();
});

it('is off unless the settings turn it on', function (): void {
    Brain\Monkey\Functions\when('get_current_blog_id')->justReturn(1);

    expect(RestPlugins::fromSettings('/wp-json/', []))->toBeNull()
        ->and(Settings::fromArray(['rest_plugins' => ['enabled' => true]], 'https://cms.example.com')->restPluginFilter)->toBeTrue()
        ->and(Settings::fromArray([], 'https://cms.example.com')->restPluginFilter)->toBeFalse();
});

it('filters the active plugins of a REST request only', function (): void {
    Filters\expectAdded('option_active_plugins')->once()->with(Mockery::type('array'), 1);

    $rest = new RestPlugins(new PluginFilter(['acf']), true);
    $rest->register(new WordPressHooks);
    (new RestPlugins(new PluginFilter(['acf']), false))->register(new WordPressHooks);

    expect($rest->plugins(['acf/acf.php', 'x/x.php']))->toBe(['acf/acf.php'])
        ->and($rest->plugins('not an array'))->toBe('not an array');
});
