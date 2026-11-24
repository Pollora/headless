<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Infrastructure\Language\LanguagesFactory;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Language\PolylangLanguages;
use Pollora\Headless\Infrastructure\Language\WpmlLanguages;

it('picks the plugin that is loaded, Polylang first', function (): void {
    expect(LanguagesFactory::from(true, true))->toBeInstanceOf(PolylangLanguages::class)
        ->and(LanguagesFactory::from(false, true))->toBeInstanceOf(WpmlLanguages::class)
        ->and(LanguagesFactory::from(false, false))->toBeInstanceOf(NoLanguages::class);
});

it('has no language without a plugin', function (): void {
    $none = new NoLanguages;

    expect($none->plugin())->toBe('none')
        ->and($none->isActive())->toBeFalse()
        ->and($none->all())->toBe([])
        ->and($none->current())->toBeNull()
        ->and($none->ofPost(1))->toBeNull()
        ->and($none->translations(1))->toBe([])
        ->and($none->homeUrl('fr'))->toBeNull();
});

it('reads Polylang through its functions', function (): void {
    Functions\when('pll_languages_list')->justReturn(['en', 'fr', 7]);
    Functions\when('pll_current_language')->justReturn('fr');
    Functions\when('pll_get_post_language')->justReturn('en');
    Functions\when('pll_get_post_translations')->justReturn(['en' => 4, 'fr' => '9', 'de' => 'x']);
    Functions\when('pll_home_url')->justReturn('https://cms.example.com/fr/');

    $polylang = new PolylangLanguages;

    expect($polylang->plugin())->toBe('polylang')
        ->and($polylang->isActive())->toBeTrue()
        ->and($polylang->all())->toBe(['en', 'fr'])
        ->and($polylang->current())->toBe('fr')
        ->and($polylang->ofPost(4))->toBe('en')
        ->and($polylang->translations(4))->toBe(['en' => 4, 'fr' => 9])
        ->and($polylang->homeUrl('fr'))->toBe('https://cms.example.com/fr/');
});

it('treats an empty Polylang answer as no language', function (): void {
    Functions\when('pll_languages_list')->justReturn(false);
    Functions\when('pll_current_language')->justReturn(false);
    Functions\when('pll_get_post_language')->justReturn(false);
    Functions\when('pll_get_post_translations')->justReturn(false);
    Functions\when('pll_home_url')->justReturn('');

    $polylang = new PolylangLanguages;

    expect($polylang->all())->toBe([])
        ->and($polylang->current())->toBeNull()
        ->and($polylang->ofPost(4))->toBeNull()
        ->and($polylang->translations(4))->toBe([])
        ->and($polylang->homeUrl('fr'))->toBeNull();
});

it('reads WPML through its filters', function (): void {
    Functions\when('get_post_type')->justReturn('post');
    Filters\expectApplied('wpml_active_languages')->andReturn(['en' => [], 'fr' => []]);
    Filters\expectApplied('wpml_current_language')->andReturn('fr');
    Filters\expectApplied('wpml_post_language_details')->andReturn(['language_code' => 'en']);
    Filters\expectApplied('wpml_element_trid')->andReturn(55);
    Filters\expectApplied('wpml_get_element_translations')->andReturn(['en' => (object) ['element_id' => '4'], 'fr' => (object) ['element_id' => 9], 'de' => 'x']);
    Functions\when('home_url')->justReturn('https://cms.example.com/');
    Filters\expectApplied('wpml_permalink')->andReturn('https://cms.example.com/fr/');

    $wpml = new WpmlLanguages;

    expect($wpml->plugin())->toBe('wpml')
        ->and($wpml->all())->toBe(['en', 'fr'])
        ->and($wpml->current())->toBe('fr')
        ->and($wpml->ofPost(4))->toBe('en')
        ->and($wpml->translations(4))->toBe(['en' => 4, 'fr' => 9])
        ->and($wpml->homeUrl('fr'))->toBe('https://cms.example.com/fr/');
});

it('treats an empty WPML answer as no language, and "all" as none', function (): void {
    Functions\when('get_post_type')->justReturn('post');
    Filters\expectApplied('wpml_active_languages')->andReturn(null);
    Filters\expectApplied('wpml_current_language')->andReturn('all');
    Filters\expectApplied('wpml_post_language_details')->andReturn(null);
    Filters\expectApplied('wpml_element_trid')->andReturn(null);
    Filters\expectApplied('wpml_get_element_translations')->andReturn(null);
    Functions\when('home_url')->justReturn('https://cms.example.com/');
    Filters\expectApplied('wpml_permalink')->andReturn('');

    $wpml = new WpmlLanguages;

    expect($wpml->all())->toBe([])
        ->and($wpml->current())->toBeNull()
        ->and($wpml->ofPost(4))->toBeNull()
        ->and($wpml->translations(4))->toBe([])
        ->and($wpml->homeUrl('fr'))->toBeNull()
        ->and($wpml->isActive())->toBeFalse();
});
