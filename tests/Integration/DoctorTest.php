<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\Status\Check;
use Pollora\Headless\Infrastructure\Status\CheckLevel;
use Pollora\Headless\Infrastructure\Status\Doctor;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\DoctorProbe;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The doctor against a real WordPress: it tries the REST API as a visitor and reads what the site really answers.
 */
afterEach(function (): void {
    foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if (is_array($function) && is_object($function[0]) && str_starts_with($function[0]::class, 'Pollora\\Headless\\')) {
                    remove_filter($hook, $function, $priority);
                }
            }
        }
    }

    delete_option('permalink_structure');
    wp_set_current_user(0);
});

/**
 * @return array<string, Check>
 */
function doctorRun(): array
{
    $settings = Settings::fromArray(['frontend_url' => 'https://www.example.com'], home_url());
    Bootstrap::boot($settings, new WordPressHooks, new NoLanguages);
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();

    $facts = (new DoctorProbe(new RedirectCatalog([new OptionRedirectStore])))->facts();
    $checks = [];

    foreach (Doctor::run($settings, $facts) as $check) {
        $checks[$check->id] = $check;
    }

    return $checks;
}

it('sees that the package closes the users route and leaves the content open', function (): void {
    update_option('permalink_structure', '/%postname%/');
    wp_set_current_user(1);

    $checks = doctorRun();

    expect($checks['users-route']->level)->toBe(CheckLevel::Ok)
        ->and($checks['rest-open']->level)->toBe(CheckLevel::Ok)
        ->and($checks['permalinks']->level)->toBe(CheckLevel::Ok)
        ->and($checks['cors']->level)->toBe(CheckLevel::Ok)
        ->and(get_current_user_id())->toBe(1);
});

it('fails on plain permalinks', function (): void {
    update_option('permalink_structure', '');

    expect(doctorRun()['permalinks']->level)->toBe(CheckLevel::Fail);
});

it('sees WPGraphQL and its closed schema', function (): void {
    update_option('permalink_structure', '/%postname%/');

    $checks = doctorRun();

    expect($checks['graphql']->level)->toBe(CheckLevel::Ok);
});
