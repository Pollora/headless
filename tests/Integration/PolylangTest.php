<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Content\ContentLinks;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Language\LanguageUrls;
use Pollora\Headless\Infrastructure\Language\LanguagesFactory;
use Pollora\Headless\Infrastructure\Language\PolylangLanguages;
use Pollora\Headless\Infrastructure\WordPress\FrontLinks;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * Polylang is installed by the CI job (wordpress-plugins: polylang). Its languages are real ones here.
 */
beforeAll(function (): void {
    foreach ([['English', 'en', 'en_US', 0], ['Français', 'fr', 'fr_FR', 1]] as [$name, $slug, $locale, $order]) {
        if (PLL()->model->get_language($slug) === false) {
            PLL()->model->add_language(['name' => $name, 'slug' => $slug, 'locale' => $locale, 'rtl' => 0, 'term_group' => $order, 'flag' => $slug === 'fr' ? 'fr' : 'us']);
        }
    }
});

afterAll(function (): void {
    foreach (['en', 'fr'] as $slug) {
        $language = PLL()->model->get_language($slug);

        if ($language !== false) {
            PLL()->model->delete_language((int) $language->term_id);
        }
    }
});

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
});

it('detects Polylang and lists its languages', function (): void {
    $languages = LanguagesFactory::detect();

    expect($languages)->toBeInstanceOf(PolylangLanguages::class)
        ->and($languages->isActive())->toBeTrue()
        ->and($languages->all())->toContain('en', 'fr')
        ->and($languages->homeUrl('fr'))->toBeString();
});

it('knows the language of a post and its translations', function (): void {
    $english = wp_insert_post(['post_title' => 'Hello', 'post_status' => 'publish']);
    $french = wp_insert_post(['post_title' => 'Bonjour', 'post_status' => 'publish']);
    pll_set_post_language($english, 'en');
    pll_set_post_language($french, 'fr');
    pll_save_post_translations(['en' => $english, 'fr' => $french]);

    $languages = new PolylangLanguages;
    $translations = $languages->translations($english);
    $language = $languages->ofPost($french);

    wp_delete_post($english, true);
    wp_delete_post($french, true);

    expect($language)->toBe('fr')
        ->and($translations)->toBe(['en' => $english, 'fr' => $french]);
});

it('sends each post to the front end of its language', function (): void {
    $backend = Origin::fromUrl(home_url());
    $front = Origin::fromUrl('https://www.example.com');
    $languages = new PolylangLanguages;
    $urls = new LanguageUrls(new UrlMapper($backend, $front), $backend, $front, ['fr' => Origin::fromUrl('https://www.example.fr')], $languages);
    (new FrontLinks($urls, new ContentLinks($urls), $languages))->register(new WordPressHooks);

    $english = wp_insert_post(['post_title' => 'Hello', 'post_status' => 'publish']);
    $french = wp_insert_post(['post_title' => 'Bonjour', 'post_status' => 'publish']);
    pll_set_post_language($english, 'en');
    pll_set_post_language($french, 'fr');

    $englishLink = get_permalink($english);
    $frenchLink = get_permalink($french);
    wp_delete_post($english, true);
    wp_delete_post($french, true);

    expect($englishLink)->toStartWith('https://www.example.com/')
        ->and($frenchLink)->toStartWith('https://www.example.fr/');
});
