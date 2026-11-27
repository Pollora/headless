<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use GraphQL\Error\UserError;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Block\BlockTree;
use Pollora\Headless\Domain\Cache\RestCachePolicy;
use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Form\FormProvider;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Domain\Media\MediaCatalog;
use Pollora\Headless\Domain\Plugin\PluginFilter;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectSource;
use Pollora\Headless\Domain\Seo\SeoProvider;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\Form\ContactForm7Provider;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\RankMathRedirects;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\FormRoutes;
use Pollora\Headless\Infrastructure\WordPress\GraphQl;
use Pollora\Headless\Infrastructure\WordPress\MediaRoutes;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\RestPerformance;
use Pollora\Headless\Infrastructure\WordPress\RestPlugins;
use Pollora\Headless\Infrastructure\WordPress\SeoField;
use Pollora\Headless\Infrastructure\WordPress\TokenAuthentication;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

use function Pollora\Headless\Tests\Fixtures\isolated;

// WPGraphQL's error class is not loaded in the unit suite
if (! class_exists('GraphQL\Error\UserError')) {
    eval('namespace GraphQL\Error; class UserError extends \Exception {}');
}

afterEach(function (): void {
    unset($GLOBALS['wpdb'], $GLOBALS['post'], $_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']);
    WP_Block_Type_Registry::$types = [];
});

function leakRequest(string $fields = ''): WP_REST_Request
{
    $request = new WP_REST_Request('GET', '/wp/v2/posts');
    $request->set_param('_fields', $fields);

    return $request;
}

/** @param list<object> $rows */
function leakDatabase(array $rows): void
{
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->posts = 'wp_posts';
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('prepare')->andReturnUsing(static fn (string $sql): string => $sql);
    $wpdb->shouldReceive('get_var')->andReturn('wp_rank_math_redirections');
    $wpdb->shouldReceive('get_results')->andReturn($rows);
    $GLOBALS['wpdb'] = $wpdb;
}

function leakRank(array $sources, string $to = '/new/', mixed $code = '301'): object
{
    return (object) ['sources' => serialize($sources), 'url_to' => $to, 'header_code' => $code];
}

// Media, blocks and SEO

it('describes the featured image of the post asked about, and nothing when it has none', function (): void {
    $media = Mockery::mock(MediaCatalog::class);
    $media->shouldReceive('for')->with(9)->andReturn(['id' => 9]);
    $media->shouldReceive('for')->with(1)->andReturn(['id' => 1]);
    Functions\when('get_post_thumbnail_id')->alias(static fn (mixed $post): int|false => match ($post) {
        5 => 9,
        6 => 1,
        0 => false,
        default => 9999,
    });
    $routes = new MediaRoutes($media);

    expect($routes->featured(['id' => 5], 'headless_featured_media', leakRequest('headless_featured_media')))->toBe(['id' => 9])
        ->and($routes->featured(['id' => 6], 'headless_featured_media', leakRequest('headless_featured_media')))->toBe(['id' => 1])
        ->and($routes->featured([], 'headless_featured_media', leakRequest('headless_featured_media')))->toBeNull();
});

it('asks for the blocks of no post when the answer names none', function (): void {
    $asked = new ArrayObject;
    Functions\when('get_post')->alias(static function (mixed $id) use ($asked): ?WP_Post {
        $asked[] = $id;

        return null;
    });

    (new BlocksField)->blocks([], 'headless_blocks', leakRequest('headless_blocks'));

    expect($asked->getArrayCopy())->toBe([0]);
});

it('renders a damaged block from the parts a block has, and marks dynamic inner blocks only', function (): void {
    $rendered = new ArrayObject;
    WP_Block_Type_Registry::$types = ['a/dynamic' => new WP_Block_Type(null, true), 'a/static' => new WP_Block_Type(null, false)];
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('parse_blocks')->justReturn([[
        'blockName' => 'a/static',
        'innerBlocks' => [
            ['blockName' => 'a/dynamic', 'innerBlocks' => 'broken', 'extra' => 1],
            ['blockName' => 'a/static'],
            ['blockName' => 'a/unknown'],
        ],
    ]]);
    Functions\when('render_block')->alias(static function (array $block) use ($rendered): string {
        $rendered[] = $block;

        return 'html';
    });

    $blocks = BlocksField::forContent('content', new WP_Post);

    expect(array_map(static fn (array $block): bool => array_key_exists('rendered', $block), $blocks[0]['inner_blocks']))->toBe([true, false, false])
        ->and($rendered[0]['innerBlocks'])->toHaveCount(3)
        ->and($rendered[0]['attrs'])->toBe([])
        ->and($rendered[0]['innerContent'])->toBe([])
        ->and($rendered[0]['innerHTML'])->toBe('')
        ->and($rendered[1])->toBe(['blockName' => 'a/dynamic', 'innerBlocks' => [], 'extra' => 1, 'attrs' => [], 'innerHTML' => '', 'innerContent' => []]);
});

it('puts the earlier post back after rendering blocks', function (bool $had): void {
    $setUp = new ArrayObject;
    $before = $had ? new WP_Post(['ID' => 1]) : null;
    $GLOBALS['post'] = $before;
    Functions\when('setup_postdata')->alias(static function (WP_Post $post) use ($setUp): void {
        $setUp[] = $post->ID;
    });
    Functions\when('parse_blocks')->justReturn([]);

    BlocksField::forContent('', new WP_Post(['ID' => 2]));

    expect($setUp->getArrayCopy())->toBe($had ? [2, 1] : [2])->and($GLOBALS['post'])->toBe($before);
})->with([true, false]);

it('numbers inner blocks by their order, whatever the keys of the parser', function (): void {
    $tree = BlockTree::from(
        [['blockName' => 'a/b', 'innerBlocks' => [7 => ['blockName' => 'a/c'], 3 => ['blockName' => 'a/d']]]],
        static fn (string $name, array $attributes): array => $attributes,
        static fn (array $block): string => '',
        static fn (string $name): bool => false,
    );

    expect(array_column($tree[0]['inner_blocks'], 'id'))->toBe(['0.0', '0.1']);
});

it('asks the SEO source about the post or term of the answer, and about nothing when it names none', function (): void {
    $asked = new ArrayObject;
    $provider = Mockery::mock(SeoProvider::class);
    $provider->shouldReceive('forPost')->andReturnUsing(static function (int $id) use ($asked) {
        $asked[] = ['post', $id];

        return null;
    });
    $provider->shouldReceive('forTerm')->andReturnUsing(static function (int $id, string $taxonomy) use ($asked) {
        $asked[] = ['term', $id, $taxonomy];

        return null;
    });
    $seo = new SeoField($provider, Mockery::mock(FrontUrls::class));

    expect($seo->forPost([], 'headless_seo', leakRequest('headless_seo')))->toBeNull()
        ->and($seo->forTerm([], 'headless_seo', leakRequest('headless_seo')))->toBeNull()
        ->and($asked->getArrayCopy())->toBe([['post', 0], ['term', 0, '']]);
});

// Redirects

it('lists no more rules than the limit, and says when it cut them', function (int $count, bool $truncated): void {
    $rules = array_map(static fn (int $i): Redirect => new Redirect('/old-'.$i, '/new'), range(1, $count));
    $source = Mockery::mock(RedirectSource::class);
    $source->shouldReceive('redirects')->andReturn($rules);
    Functions\when('rest_ensure_response')->alias(static fn (array $data): WP_REST_Response => new WP_REST_Response($data));

    $data = (new RedirectRoutes(new RedirectCatalog([$source])))->all()->get_data();

    expect($data['count'])->toBe($count)
        ->and($data['redirects'])->toHaveCount(min($count, 5000))
        ->and($data['truncated'])->toBe($truncated);
})->with([[5000, false], [5001, true]]);

it('reads from Rank Math every rule after one it cannot use', function (): void {
    leakDatabase([
        leakRank([['pattern' => 'contains', 'comparison' => 'contains'], ['pattern' => 'one', 'comparison' => 'exact']]),
        leakRank([['pattern' => 'bad', 'comparison' => 'exact'], ['pattern' => 'two', 'comparison' => 'exact']], code: '999'),
        leakRank([['pattern' => '/three', 'comparison' => 'exact'], 'not a source', ['comparison' => 'exact'], ['pattern' => 'x']]),
    ]);

    expect(array_map(static fn (Redirect $rule): string => $rule->from, (new RankMathRedirects)->redirects()))->toBe(['/one', '/three']);
});

it('instantiates no object held in the sources of Rank Math', function (): void {
    $payload = 'a:1:{i:0;a:2:{s:7:"pattern";s:3:"one";s:10:"comparison";O:8:"stdClass":0:{}}}';
    leakDatabase([(object) ['sources' => $payload, 'url_to' => '/new/', 'header_code' => '301']]);

    expect((new RankMathRedirects)->redirects())->toBe([]);
});

it('skips a Rank Math rule with no destination or status', function (): void {
    leakDatabase([(object) ['sources' => serialize([['pattern' => 'one', 'comparison' => 'exact']])]]);

    expect((new RankMathRedirects)->redirects())->toBe([]);
});

it('reads no redirection without a database', function (): void {
    expect((new RankMathRedirects)->redirects())->toBe([]);
});

it('lists the old slugs after the ones it has to leave out', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    $row = static fn (mixed $id, mixed $slug): object => (object) ['post_id' => $id, 'old_slug' => $slug];
    leakDatabase([$row(null, 'x'), $row('1', 'now'), $row('2', 'same'), $row('3', 'before')]);
    $asked = new ArrayObject;
    Functions\when('get_post')->alias(static function (mixed $id) use ($asked): WP_Post {
        $asked[] = $id;

        // Post 2 has plain permalinks: both of its addresses are the same
        return new WP_Post(['ID' => $id, 'post_name' => 'now', 'post_type' => $id === 2 ? 'plain' : 'post']);
    });
    Functions\when('is_post_type_viewable')->justReturn(true);
    Functions\when('get_permalink')->alias(static fn (WP_Post $post): string => 'https://cms.example.com/'.($post->post_type === 'plain' ? '?p=2' : $post->post_name.'/'));
    $urls = Mockery::mock(FrontUrls::class);
    $urls->shouldReceive('toFront')->andReturnArg(0);

    $rules = (new OldSlugRedirects($urls))->redirects();

    expect(array_map(static fn (Redirect $rule): array => $rule->toArray(), $rules))->toBe([['from' => '/before/', 'to' => '/now/', 'status' => 301, 'regex' => false, 'source' => 'old-slug']])
        ->and($asked->getArrayCopy())->toBe([1, 2, 3]);
});

it('gives the cached old slugs as a list, without the ones it cannot read', function (): void {
    Functions\when('get_transient')->justReturn(['junk', ['from' => '/a'], ['from' => '/b', 'to' => '/c']]);

    $rules = (new OldSlugRedirects(Mockery::mock(FrontUrls::class)))->redirects();

    expect(array_keys($rules))->toBe([0])->and($rules[0]->from)->toBe('/b');
});

// Forms

it('looks past a provider that is not the one asked for', function (): void {
    $other = Mockery::mock(FormProvider::class);
    $other->shouldReceive('name')->andReturn('other');
    $other->shouldReceive('find')->never();
    $wanted = Mockery::mock(FormProvider::class);
    $wanted->shouldReceive('name')->andReturn('cf7');
    $wanted->shouldReceive('find')->once()->with('12')->andReturn(null);
    Functions\when('apply_filters')->justReturn([1 => 'junk', 4 => $other, 6 => $wanted]);
    $request = new WP_REST_Request('GET', '/headless/v1/forms/cf7/12');
    $request->set_param('provider', 'cf7');
    $request->set_param('id', '12');

    $error = (new FormRoutes([]))->show($request);

    expect([$error->get_error_code(), $error->get_error_data()])->toBe(['headless_form_not_found', ['status' => 404]]);
});

it('reads every Contact Form 7 form, and the choices of the fields that have some', function (): void {
    require_once dirname(__DIR__, 2).'/Stubs/contact-form-7.php';
    Functions\when('rest_url')->returnArg(1);
    $tags = [
        'not a tag',
        new WPCF7_FormTag('radio', 'size', [5 => 's', 9 => 'l'], ['Small']),
        new WPCF7_FormTag('text', 'who', ['default']),
        new WPCF7_FormTag('select', 'one', ['a'], ['A']),
        new WPCF7_FormTag('select', 'many', ['a'], ['A'], ['multiple']),
        new WPCF7_FormTag('radio', 'flagged', [], [], ['multiple']),
        new WPCF7_FormTag('checkbox', 'boxes', 'x', 'X'),
        new WPCF7_FormTag('checkbox', 'alone', ['y'], ['Y'], ['exclusive']),
    ];
    WPCF7_ContactForm::$forms = [12 => new WPCF7_ContactForm(12, 'Contact', 'fr_FR', $tags)];

    $fields = array_map(static fn ($field): array => [$field->name, $field->multiple, $field->options], (new ContactForm7Provider)->forms()[0]->fields);
    WPCF7_ContactForm::$forms = [];

    expect($fields)->toBe([
        ['size', false, [['value' => 's', 'label' => 'Small'], ['value' => 'l', 'label' => 'l']]],
        ['who', false, []],
        ['one', false, [['value' => 'a', 'label' => 'A']]],
        ['many', true, [['value' => 'a', 'label' => 'A']]],
        ['flagged', false, []],
        ['boxes', true, [['value' => 'x', 'label' => 'X']]],
        ['alone', false, [['value' => 'y', 'label' => 'Y']]],
    ]);
});

// REST performance

it('gives each of its filters the priority and arguments it reads', function (): void {
    Filters\expectAdded('rest_post_dispatch')->once()->with(Mockery::type('array'), 20, 3);
    Filters\expectAdded('rest_pre_serve_request')->once()->with(Mockery::type('array'), 5, 3);
    Filters\expectAdded('rest_pre_dispatch')->once()->with(Mockery::type('array'), 5, 3);

    (new RestPerformance(new RestCachePolicy, 20))->register(new WordPressHooks);
});

it('lowers per_page only above the ceiling', function (mixed $asked, ?int $ceiling, bool $lowered): void {
    $request = new WP_REST_Request;
    $request->set_param('per_page', $asked);

    (new RestPerformance(null, $ceiling))->capPerPage(null, null, $request);

    expect($request->get_param('per_page'))->toBe($lowered ? $ceiling : $asked);
})->with([
    'under' => [19, 20, false],
    'at' => ['20', 20, false],
    'over' => ['21', 20, true],
    'not a number' => ['many', 20, false],
    'a decimal just over' => ['20.5', 20, false],
    'no ceiling' => [500, null, false],
]);

it('does not mark answers cacheable without a policy', function (): void {
    $response = new WP_REST_Response(['id' => 1]);

    (new RestPerformance(null, 20))->markCacheable($response, null, new WP_REST_Request('GET', '/wp/v2/posts'));

    expect($response->get_headers())->toBe([]);
});

// Plugins loaded on a REST request

it('registers the plugin filter on a REST request only', function (bool $rest): void {
    Filters\expectAdded('option_active_plugins')->times($rest ? 1 : 0);

    (new RestPlugins(new PluginFilter([]), $rest))->register(new WordPressHooks);
})->with([true, false]);

it('takes the plugins to keep from the settings of the current site, only when switched on', function (string $setup, string $config, string $expected): void {
    $code = $setup."define('HEADLESS_CONFIG', ".$config.'); '
        .'$module = Pollora\Headless\Infrastructure\WordPress\RestPlugins::fromSettings("/wp-json/", []); '
        .'echo json_encode($module?->plugins(["a/a.php", "b/b.php"]));';

    expect(isolated($code))->toBe($expected);
})->with([
    'switched on' => ['', "['rest_plugins' => ['enabled' => true, 'keep' => ['a']]]", '["a\/a.php"]'],
    'no switch' => ['', "['rest_plugins' => ['keep' => ['a']]]", 'null'],
    'a truthy switch is not on' => ['', "['rest_plugins' => ['enabled' => 1, 'keep' => ['a']]]", 'null'],
    'switched off' => ['', "['rest_plugins' => ['enabled' => false]]", 'null'],
    'not a list of settings' => ['', "['rest_plugins' => true]", 'null'],
    'the first site without multisite' => ['', "['sites' => [0 => [], 1 => ['rest_plugins' => ['enabled' => true, 'keep' => ['b']]], 2 => []]]", '["b\/b.php"]'],
    'the current site' => ['function get_current_blog_id() { return 3; } ', "['sites' => [1 => [], 3 => ['rest_plugins' => ['enabled' => true, 'keep' => ['b']]]]]", '["b\/b.php"]'],
]);

// GraphQL

it('keeps one Vary list from WPGraphQL and the policy', function (): void {
    $_SERVER['HTTP_ORIGIN'] = 'https://a.test';
    $graphql = new GraphQl(new GraphQlPolicy, new CorsPolicy(['https://a.test']));

    $headers = $graphql->headers(['Vary' => ' Accept-Encoding ,, Origin ,Cookie']);

    expect($headers['Vary'])->toBe('Accept-Encoding, Origin, Cookie')
        ->and($headers['Access-Control-Allow-Origin'])->toBe('https://a.test');
});

it('does nothing at a root field without the token module', function (): void {
    expect((new GraphQl(new GraphQlPolicy))->guard('kept', null, [], null, null, 'RootMutation'))->toBe('kept');
});

it('refuses a mutation to a token limited to reading, after letting its query through', function (): void {
    $service = new TokenService(new InMemoryTokenStore);
    $auth = new TokenAuthentication($service, static fn (): bool => true);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$service->create(7, 'a', [TokenScope::Read], ['/graphql'])->secret;
    $auth->authenticate(false);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $guard = new GraphQl(new GraphQlPolicy, null, $auth);

    expect($guard->guard(null, null, [], null, null, 'RootQuery'))->toBeNull()
        ->and(static fn () => $guard->guard(null, null, [], null, null, 'RootMutation'))->toThrow(UserError::class);
});
