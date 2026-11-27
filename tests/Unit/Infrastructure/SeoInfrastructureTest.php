<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoProvider;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Redirect\RankMathRedirects;
use Pollora\Headless\Infrastructure\Seo\JsonFieldProvider;
use Pollora\Headless\Infrastructure\Seo\NoSeo;
use Pollora\Headless\Infrastructure\Seo\RankMathProvider;
use Pollora\Headless\Infrastructure\Seo\SeopressProvider;
use Pollora\Headless\Infrastructure\Seo\SeoProviders;
use Pollora\Headless\Infrastructure\WordPress\SeoField;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

it('picks the SEO plugin that is loaded, Yoast first', function (): void {
    expect(SeoProviders::from(true, true, true, true)->name())->toBe('yoast')
        ->and(SeoProviders::from(false, true, true, true))->toBeInstanceOf(RankMathProvider::class)
        ->and(SeoProviders::from(false, false, true, true)->name())->toBe('aioseo')
        ->and(SeoProviders::from(false, false, false, true))->toBeInstanceOf(SeopressProvider::class)
        ->and(SeoProviders::from(false, false, false, false))->toBeInstanceOf(NoSeo::class)
        ->and(SeoProviders::from(true, false, false, false))->toBeInstanceOf(JsonFieldProvider::class);
});

it('has nothing to give without a SEO plugin', function (): void {
    $none = new NoSeo;

    expect($none->name())->toBe('none')->and($none->isActive())->toBeFalse()->and($none->forPost(1))->toBeNull()->and($none->forTerm(1, 'category'))->toBeNull();
});

function fakeSeoProvider(): SeoProvider
{
    return new class implements SeoProvider
    {
        public function name(): string
        {
            return 'fake';
        }

        public function isActive(): bool
        {
            return true;
        }

        public function forPost(int $postId): ?Seo
        {
            return $postId === 12 ? new Seo('fake', 'Title', canonical: 'https://cms.example.com/hello/') : null;
        }

        public function forTerm(int $termId, string $taxonomy): ?Seo
        {
            return $termId === 5 && $taxonomy === 'category' ? new Seo('fake', 'Category', canonical: 'https://cms.example.com/category/news/') : null;
        }
    };
}

function seoField(): SeoField
{
    return new SeoField(fakeSeoProvider(), new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com')));
}

function seoRequest(?string $fields): WP_REST_Request
{
    $request = Mockery::mock('WP_REST_Request');
    $request->shouldReceive('get_param')->with('_fields')->andReturn($fields);

    return $request;
}

it('registers the field on public post types and taxonomies', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    $module = seoField();
    $module->register(new WordPressHooks);

    Functions\when('get_post_types')->justReturn(['post', 'page']);
    Functions\when('get_taxonomies')->justReturn(['category']);
    Functions\expect('register_rest_field')->times(3)->with(Mockery::anyOf('post', 'page', 'category'), 'headless_seo', Mockery::type('array'));

    $module->fields();
});

it('gives the metadata of a post or a term on the front end, only when asked for it', function (): void {
    $module = seoField();

    expect($module->forPost(['id' => 12], 'headless_seo', seoRequest('id,headless_seo'))['canonical'])->toBe('https://www.example.com/hello/')
        ->and($module->forPost(['id' => 12], 'headless_seo', seoRequest('id,title')))->toBeNull()
        ->and($module->forPost(['id' => 99], 'headless_seo', seoRequest('headless_seo')))->toBeNull()
        ->and($module->forTerm(['id' => 5, 'taxonomy' => 'category'], 'headless_seo', seoRequest('headless_seo'))['canonical'])->toBe('https://www.example.com/category/news/')
        ->and($module->forTerm(['id' => 5, 'taxonomy' => 'category'], 'headless_seo', seoRequest(null)))->toBeNull();
});

it('reads the SEO setting', function (): void {
    expect(Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com')->seo)->toBeTrue()
        ->and(Settings::fromArray(['frontend_url' => 'https://www.example.com', 'seo' => ['enabled' => false]], 'https://cms.example.com')->seo)->toBeFalse()
        ->and(Settings::fromArray([], 'https://cms.example.com')->seo)->toBeFalse();
});

function rankMathDatabase(bool $tableExists, array $rows): void
{
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $sql, mixed ...$args): string => $sql);
    $wpdb->shouldReceive('get_var')->andReturn($tableExists ? 'wp_rank_math_redirections' : null);
    $wpdb->shouldReceive('get_results')->andReturn($rows);
    $GLOBALS['wpdb'] = $wpdb;
}

it('reads the exact and pattern redirections of Rank Math, with their status', function (): void {
    rankMathDatabase(true, [
        (object) ['sources' => serialize([['pattern' => 'old-page', 'comparison' => 'exact'], ['pattern' => 'blog/(.*)', 'comparison' => 'regex'], ['pattern' => 'shop', 'comparison' => 'contains'], ['pattern' => '', 'comparison' => 'exact']]), 'url_to' => '/new-page/', 'header_code' => '301'],
        (object) ['sources' => serialize([['pattern' => '/bad', 'comparison' => 'exact']]), 'url_to' => 'not a destination', 'header_code' => '301'],
        (object) ['sources' => 'broken', 'url_to' => '/x/', 'header_code' => '301'],
        (object) ['sources' => serialize([['pattern' => '/gone/', 'comparison' => 'exact']]), 'url_to' => '', 'header_code' => '410'],
    ]);

    $rules = (new RankMathRedirects)->redirects();

    expect(array_map(static fn ($rule): string => $rule->from.($rule->regex ? ' (regex)' : '').' → '.$rule->to.' '.$rule->status.' '.$rule->source, $rules))->toBe([
        '/old-page → /new-page/ 301 rankmath',
        '/blog/(.*) (regex) → /new-page/ 301 rankmath',
        '/gone/ →  410 rankmath',
    ]);
    unset($GLOBALS['wpdb']);
});

it('reads no redirection from a Rank Math whose table is not there', function (): void {
    rankMathDatabase(false, []);

    expect((new RankMathRedirects)->redirects())->toBe([]);
    unset($GLOBALS['wpdb']);
});
