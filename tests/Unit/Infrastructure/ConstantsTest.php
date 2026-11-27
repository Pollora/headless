<?php

declare(strict_types=1);

use function Pollora\Headless\Tests\Fixtures\isolated;

/*
 * What constants of wp-config.php and the end of a request decide, each in a process of its own.
 */

it('takes the signing secret and the revalidation target from their own constants', function (): void {
    $config = json_decode(isolated(<<<'PHP'
        define('HEADLESS_CONFIG', ['preview' => ['link_ttl' => 90, 'secret' => 'from-the-array'], 'revalidation' => ['targets' => [['name' => 'first']], 'debounce' => 9]]);
        define('HEADLESS_FRONTEND_URL', 'https://www.example.com');
        define('HEADLESS_SECRET', 'from-the-constant');
        define('HEADLESS_REVALIDATE_URL', 'https://www.example.com/api/revalidate');
        define('HEADLESS_REVALIDATE_SECRET', 'shared');
        echo json_encode(Pollora\Headless\Infrastructure\Config\SettingsLoader::config());
        PHP), true);

    expect($config)->toBe([
        'preview' => ['link_ttl' => 90, 'secret' => 'from-the-constant'],
        'revalidation' => ['targets' => [['name' => 'first'], ['name' => 'default', 'url' => 'https://www.example.com/api/revalidate', 'secret' => 'shared']], 'debounce' => 9],
        'frontend_url' => 'https://www.example.com',
    ]);
});

it('keeps the secret of the settings when the constant is empty, and adds no target without both constants', function (): void {
    $config = json_decode(isolated(<<<'PHP'
        define('HEADLESS_CONFIG', ['preview' => ['secret' => 'from-the-array']]);
        define('HEADLESS_SECRET', '');
        define('HEADLESS_REVALIDATE_URL', 'https://www.example.com/api/revalidate');
        echo json_encode(Pollora\Headless\Infrastructure\Config\SettingsLoader::config());
        PHP), true);

    expect($config)->toBe(['preview' => ['secret' => 'from-the-array']]);
});

it('filters the plugins of a REST request when the settings turn the filter on', function (string $uri, string $query, string $expected): void {
    $output = isolated(<<<PHP
        define('HEADLESS_CONFIG', ['rest_plugins' => ['enabled' => true, 'keep' => ['acf', 12]]]);
        \$hooks = new class implements Pollora\Headless\Infrastructure\WordPress\HookRegistrar {
            public function addAction(string \$hook, callable \$callback, int \$priority = 10, int \$acceptedArgs = 1): void {}
            public function addFilter(string \$hook, callable \$callback, int \$priority = 10, int \$acceptedArgs = 1): void
            {
                echo \$hook.' '.json_encode(\$callback(['acf/acf.php', 'other/other.php']));
            }
        };
        Pollora\Headless\Infrastructure\WordPress\RestPlugins::fromSettings('$uri', $query)?->register(\$hooks);
        PHP);

    expect($output)->toBe($expected);
})->with([
    'a REST address' => ['/wp-json/wp/v2/posts', '[]', 'option_active_plugins ["acf\/acf.php"]'],
    'the rest_route form' => ['/index.php', "['rest_route' => '/wp/v2/posts']", 'option_active_plugins ["acf\/acf.php"]'],
    'a page' => ['/blog/', '[]', ''],
]);

it('ends the request once it has answered for the front', function (): void {
    $output = isolated(<<<'PHP'
        function wp_redirect(string $location, int $status): bool { echo $status.' '.$location; return true; }
        $_SERVER['REQUEST_URI'] = '/blog';
        $origin = Pollora\Headless\Domain\Front\Origin::fromUrl(...);
        $policy = new Pollora\Headless\Domain\Front\FrontGatePolicy(Pollora\Headless\Domain\Front\FrontMode::Redirect, Pollora\Headless\Domain\Front\AllowList::defaults(), $origin('https://cms.example.com'), $origin('https://www.example.com'));
        (new Pollora\Headless\Infrastructure\WordPress\TemplateRedirectGate($policy))->handle();
        echo ' the page was rendered';
        PHP);

    expect($output)->toBe('302 https://www.example.com/blog');
});
