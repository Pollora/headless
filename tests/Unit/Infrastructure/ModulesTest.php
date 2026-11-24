<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\AllowList;
use Pollora\Headless\Domain\Front\FrontGatePolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\CleanUp;
use Pollora\Headless\Infrastructure\WordPress\TemplateRedirectGate;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function gateFor(FrontMode $mode, array &$ended): TemplateRedirectGate
{
    $policy = new FrontGatePolicy($mode, AllowList::defaults(), Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'), blockedStatus: 404);

    return new TemplateRedirectGate($policy, function () use (&$ended): void {
        $ended[] = true;
    });
}

it('hooks the gate on template_redirect after redirect_canonical', function (): void {
    Actions\expectAdded('template_redirect')->once()->with(Mockery::type('array'), 11);

    $ended = [];
    gateFor(FrontMode::Redirect, $ended)->register(new WordPressHooks);
});

it('redirects, then ends the request', function (): void {
    $_SERVER['REQUEST_URI'] = '/blog/hello?x=1';
    Functions\expect('wp_redirect')->once()->with('https://www.example.com/blog/hello?x=1', 302, 'Pollora Headless')->andReturn(true);

    $ended = [];
    gateFor(FrontMode::Redirect, $ended)->handle();

    expect($ended)->toHaveCount(1);
});

it('lets an allow-listed request through', function (): void {
    $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
    Functions\expect('wp_redirect')->never();

    $ended = [];
    gateFor(FrontMode::Redirect, $ended)->handle();

    expect($ended)->toBeEmpty();
});

it('answers a holding page with the blocked status', function (): void {
    unset($_SERVER['REQUEST_URI']);
    Functions\expect('status_header')->once()->with(404);
    Functions\expect('nocache_headers')->once();
    Functions\when('get_bloginfo')->justReturn('My <site>');
    Functions\when('esc_html')->alias('htmlspecialchars');

    $ended = [];
    ob_start();
    gateFor(FrontMode::Page, $ended)->handle();
    $html = ob_get_clean();

    expect($html)->toContain('My &lt;site&gt;')->and($ended)->toHaveCount(1);
});

it('answers JSON', function (): void {
    $_SERVER['REQUEST_URI'] = '/blog';
    Functions\expect('status_header')->once()->with(404);
    Functions\expect('nocache_headers')->once();
    Functions\when('wp_json_encode')->alias('json_encode');

    $ended = [];
    ob_start();
    gateFor(FrontMode::Json, $ended)->handle();
    $json = ob_get_clean();

    expect(json_decode((string) $json, true))->toBe(['status' => 404, 'message' => 'This site has no public front end.']);
});

it('exits by default when it terminates', function (): void {
    $gate = new TemplateRedirectGate(new FrontGatePolicy(FrontMode::Off, AllowList::defaults(), Origin::fromUrl('https://a.test'), null));
    $_SERVER['REQUEST_URI'] = '/blog';

    $gate->handle();

    expect(true)->toBeTrue();
});

it('registers the clean-up when asked', function (): void {
    Actions\expectAdded('init')->once();
    Filters\expectAdded('xmlrpc_enabled')->once()->with('__return_false');
    Filters\expectAdded('wp_headers')->once();

    (new CleanUp(true, true))->register(new WordPressHooks);
});

it('registers nothing when the clean-up is off', function (): void {
    Actions\expectAdded('init')->never();
    Filters\expectAdded('xmlrpc_enabled')->never();

    (new CleanUp(false, false))->register(new WordPressHooks);
});

it('removes each head item with the priority it was added at', function (): void {
    Functions\expect('remove_action')->times(12);

    (new CleanUp(true, false))->cleanHead();
});

it('drops the pingback header and keeps the others', function (): void {
    expect((new CleanUp(false, true))->withoutPingbackHeader(['X-Pingback' => 'x', 'Link' => 'y']))->toBe(['Link' => 'y']);
});

it('builds the gate and the clean-up from the settings and attaches them', function (): void {
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com');

    expect(Bootstrap::modules($settings))->toHaveCount(2);

    Actions\expectAdded('template_redirect')->once();
    Actions\expectAdded('init')->once();
    Filters\expectAdded('xmlrpc_enabled')->once();
    Filters\expectAdded('wp_headers')->once();

    Bootstrap::boot($settings, new WordPressHooks);
});

it('hooks no gate when the front is left as it is', function (): void {
    $settings = Settings::fromArray([], 'https://cms.example.com');

    expect(Bootstrap::modules($settings))->toHaveCount(1);

    Actions\expectAdded('template_redirect')->never();
    Actions\expectAdded('init')->once();
    Filters\expectAdded('xmlrpc_enabled')->once();
    Filters\expectAdded('wp_headers')->once();

    Bootstrap::boot($settings, new WordPressHooks);
});

it('starts at once when plugins are already loaded', function (): void {
    Functions\when('did_action')->justReturn(1);
    Functions\when('home_url')->justReturn('https://cms.example.com');
    Actions\expectAdded('template_redirect')->never();

    Bootstrap::fromWordPress();
});

it('waits for plugins_loaded otherwise', function (): void {
    Functions\when('did_action')->justReturn(0);
    Actions\expectAdded('plugins_loaded')->once()->with(Mockery::type(Closure::class), 1);

    Bootstrap::fromWordPress();
});

it('loads the settings of a classic install from the constants', function (): void {
    $settings = SettingsLoader::load('https://cms.example.com');

    expect($settings->urls)->toBeNull();
});
