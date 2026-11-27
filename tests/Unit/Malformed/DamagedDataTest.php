<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Scalar;
use Pollora\Headless\Domain\Schema\OpenApiBuilder;
use Pollora\Headless\Domain\Seo\SeoMapper;
use Pollora\Headless\Infrastructure\Exceptions\HookServiceException;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;
use Pollora\Headless\Infrastructure\Form\ContactForm7Provider;
use Pollora\Headless\Infrastructure\Language\NoLanguages;
use Pollora\Headless\Infrastructure\Language\WpmlLanguages;
use Pollora\Headless\Infrastructure\Pollora\HeadlessServiceProvider;
use Pollora\Headless\Infrastructure\Pollora\PolloraHooks;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\RankMathRedirects;
use Pollora\Headless\Infrastructure\Revalidation\OptionPendingEvents;
use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\NormalizedMedia;
use Pollora\Headless\Infrastructure\WordPress\OptInField;
use Pollora\Headless\Infrastructure\WordPress\PreviewPostResponse;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\SiteManifest;
use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\SchemaCommand;
use Pollora\Headless\Infrastructure\WpCli\TokenCommand;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

require_once dirname(__DIR__, 2).'/Stubs/contact-form-7.php';

/*
 * What the package does with a value that is not the shape it should have: from an option, a database row,
 * a filter, a plugin or the command line.
 */

afterEach(function (): void {
    unset($GLOBALS['wpdb'], $GLOBALS['post']);
    WPCF7_ContactForm::$forms = [];
    WPCF7_ContactForm::$noise = [];
    WP_Query::$answer = [];
    WP_Query::$queries = [];
});

function frontMapper(): UrlMapper
{
    return new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com'));
}

function messages(): Output
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $all = [];

        public function line(string $message): void
        {
            $this->all[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->all[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->all[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->all[] = 'error: '.$message;
        }
    };
}

/**
 * A Pollora application reduced to what the service provider asks of it.
 *
 * @param  array<string, mixed>  $services
 */
function polloraApp(array $services): object
{
    return new class($services)
    {
        public function __construct(private array $services) {}

        public function configPath(string $file): string
        {
            return '/config/'.$file;
        }

        public function bound(string $abstract): bool
        {
            return true;
        }

        public function make(string $abstract): mixed
        {
            return $this->services[$abstract] ?? $this->services['hooks'];
        }
    };
}

function configRepository(mixed $headless): object
{
    return new class($headless)
    {
        public function __construct(private mixed $headless) {}

        public function get(string $key, mixed $default = null): mixed
        {
            return $key === 'pollora.headless' ? $this->headless : $default;
        }
    };
}

it('reads scalars as a cast would, and anything else as the default', function (mixed $value, string $text, int $number): void {
    expect(Scalar::string($value, 'd'))->toBe($text)->and(Scalar::int($value, 7))->toBe($number);
})->with([
    'text' => ['12abc', '12abc', 12],
    'integer' => [5, '5', 5],
    'true' => [true, '1', 1],
    'nothing' => [null, 'd', 7],
    'array' => [['x'], 'd', 7],
    'object' => [new stdClass, 'd', 7],
]);

it('refuses to boot on a Pollora configuration that is not settings, instead of leaving the front open', function (mixed $repository, string $key): void {
    (new HeadlessServiceProvider(polloraApp(['config' => $repository, 'hooks' => new stdClass])))->boot();
})->with([
    'headless is a string' => [fn (): object => configRepository('yes'), 'pollora.headless'],
    'no configuration repository' => [fn (): object => new stdClass, 'config'],
])->throws(InvalidSettingsException::class, 'cannot be read as settings');

it('names a hook service that is not an object', function (): void {
    (new HeadlessServiceProvider(polloraApp(['config' => configRepository([]), 'hooks' => 'nope'])))->boot();
})->throws(HookServiceException::class, 'no hook service object');

it('names a hook service that cannot add a hook', function (): void {
    (new PolloraHooks(new stdClass, new stdClass))->addAction('init', 'strlen');
})->throws(HookServiceException::class, 'The hook service "stdClass" has no add() method.');

it('lists no old slug without a database', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);

    expect((new OldSlugRedirects(frontMapper()))->redirects())->toBe([]);
});

it('skips the old-slug rows it cannot read, and never asks for the current post in their place', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    $wpdb = Mockery::mock('wpdb');
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->posts = 'wp_posts';
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_results')->andReturn(['junk', (object) ['old_slug' => 'a'], (object) ['post_id' => 'x', 'old_slug' => 'b'], (object) ['post_id' => 7, 'old_slug' => null]]);
    $GLOBALS['wpdb'] = $wpdb;
    Functions\expect('get_post')->never();

    expect((new OldSlugRedirects(frontMapper()))->redirects())->toBe([]);
});

it('reads no Rank Math rule without a database', function (): void {
    expect((new RankMathRedirects)->redirects())->toBe([]);
});

it('skips the Rank Math rows and sources it cannot read, and keeps the others', function (): void {
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_var')->andReturn('wp_rank_math_redirections');
    $source = serialize('x');
    $wpdb->shouldReceive('get_results')->andReturn([
        'junk',
        (object) ['sources' => null, 'url_to' => '/a', 'header_code' => '301'],
        (object) ['sources' => [['comparison' => 'exact', 'pattern' => 'x']], 'url_to' => '/a', 'header_code' => '301'],
        (object) ['sources' => 'arrayed', 'url_to' => ['/a'], 'header_code' => '301'],
        (object) ['sources' => 'good', 'url_to' => '/new', 'header_code' => '302'],
    ]);
    $GLOBALS['wpdb'] = $wpdb;
    Functions\when('maybe_unserialize')->alias(static fn (string $data): mixed => match ($data) {
        'arrayed' => [['comparison' => 'exact', 'pattern' => ['not text']], ['comparison' => 'exact', 'pattern' => 'bad-target']],
        'good' => [['comparison' => 'exact', 'pattern' => 'old']],
        default => $source,
    });

    expect(array_map(static fn ($rule): array => $rule->toArray(), (new RankMathRedirects)->redirects()))
        ->toBe([['from' => '/old', 'to' => '/new', 'status' => 302, 'regex' => false, 'source' => 'rankmath']]);
});

it('has no waiting change without a database', function (): void {
    expect((new OptionPendingEvents)->drain())->toBe([]);
});

it('takes a damaged waiting change off the queue, and leaves alone a row with no name', function (): void {
    $wpdb = Mockery::mock('wpdb');
    $wpdb->options = 'wp_options';
    $wpdb->shouldReceive('esc_like')->andReturnArg(0);
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_results')->andReturn([
        'junk',
        (object) ['option_value' => '{}'],
        (object) ['option_name' => 'headless_ev_broken', 'option_value' => ['not text']],
        (object) ['option_name' => 'headless_ev_ok', 'option_value' => '{"event":"ping","tags":["a"]}'],
    ]);
    $GLOBALS['wpdb'] = $wpdb;
    $deleted = [];
    Functions\when('delete_option')->alias(static function (string $name) use (&$deleted): bool {
        $deleted[] = $name;

        return true;
    });

    $events = (new OptionPendingEvents)->drain();

    expect($deleted)->toBe(['headless_ev_broken', 'headless_ev_ok'])
        ->and($events)->toHaveCount(1)
        ->and($events[0]->tags)->toBe(['a']);
});

it('describes only the forms and tags Contact Form 7 gives as such', function (): void {
    Functions\when('rest_url')->alias(static fn (string $path): string => 'https://cms.example.com/wp-json/'.$path);
    WPCF7_ContactForm::$forms = [3 => new WPCF7_ContactForm(3, ['a title'], null, [
        'junk',
        new WPCF7_FormTag(['text'], 'broken'),
        new WPCF7_FormTag('text', ['broken']),
        new WPCF7_FormTag('select', 'topic', ['sales', ['x']], ['Sales']),
    ])];
    WPCF7_ContactForm::$noise = ['not a form', null];

    $forms = (new ContactForm7Provider)->forms();
    $form = $forms[0]->toArray();

    expect($forms)->toHaveCount(1)
        ->and($form['title'])->toBe('')
        ->and(array_column($form['fields'], 'name'))->toBe(['topic'])
        ->and($form['fields'][0]['options'])->toBe([['value' => 'sales', 'label' => 'Sales'], ['value' => '', 'label' => '']])
        ->and($form['submit']['extra']->_wpcf7_locale)->toBe('');
});

it('leaves out a WPML translation whose post ID is not a number', function (): void {
    Functions\when('get_post_type')->justReturn('post');
    Functions\when('apply_filters')->alias(static fn (string $hook): mixed => $hook === 'wpml_get_element_translations'
        ? ['fr' => (object) ['element_id' => '12'], 'de' => (object) ['element_id' => 'none'], 'es' => (object) ['element_id' => ['3']]]
        : 5);

    expect((new WpmlLanguages)->translations(12))->toBe(['fr' => 12]);
});

it('checks the keys of a block before WordPress renders it, and keeps the ones a parser added', function (): void {
    $rendered = [];
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('parse_blocks')->justReturn([
        'junk',
        ['blockName' => 'acme/box', 'attrs' => 'nope', 'innerHTML' => '<p>a</p>', 'innerBlocks' => 'nope', 'innerContent' => 'nope', 'custom' => 'kept'],
    ]);
    Functions\when('render_block')->alias(static function (array $block) use (&$rendered): string {
        $rendered[] = $block;

        return 'out';
    });

    $blocks = BlocksField::forContent('x', new WP_Post);

    expect($rendered)->toBe([['blockName' => 'acme/box', 'attrs' => [], 'innerHTML' => '<p>a</p>', 'innerBlocks' => [], 'innerContent' => [], 'custom' => 'kept']])
        ->and($blocks)->toHaveCount(1)
        ->and($blocks[0]['rendered'])->toBe('out');
});

it('reads a measure of a file that is not a number as none', function (): void {
    $file = new WP_Post(['ID' => 9, 'post_type' => 'attachment', 'post_status' => 'inherit']);
    Functions\when('get_post')->justReturn($file);
    Functions\when('get_post_mime_type')->justReturn('image/jpeg');
    Functions\when('wp_get_attachment_url')->justReturn('https://cms.example.com/u/a.jpg');
    Functions\when('wp_get_attachment_metadata')->justReturn(['width' => ['800'], 'height' => 'tall', 'filesize' => '', 'sizes' => ['thumb' => ['file' => 'a-1.jpg', 'width' => ['1'], 'height' => '90']]]);
    Functions\when('get_post_meta')->justReturn(['not an alt']);
    Functions\when('wp_get_attachment_image_srcset')->justReturn(false);

    $media = (new NormalizedMedia)->for(9);

    expect($media)->toMatchArray(['width' => null, 'height' => null, 'filesize' => null, 'alt' => ''])
        ->and($media['sizes'])->toBe(['thumb' => ['url' => 'https://cms.example.com/u/a-1.jpg', 'width' => 0, 'height' => 90]]);
});

it('ignores what is not a field name in _fields', function (): void {
    $request = new WP_REST_Request;
    $request->set_param('_fields', [['headless_blocks'], new stdClass, ' headless_seo ']);

    expect(OptInField::requested($request, 'headless_seo'))->toBeTrue()
        ->and(OptInField::requested($request, 'headless_blocks'))->toBeFalse()
        ->and(OptInField::requested($request, 'Array'))->toBeFalse();
});

it('lays the preview over a field the REST answer did not give as an object', function (): void {
    $post = new WP_Post(['ID' => 5, 'post_title' => 'Saved']);
    $revision = new WP_Post(['ID' => 6, 'post_parent' => 5, 'post_title' => 'Draft']);
    Functions\when('get_post')->justReturn($post);
    Functions\when('wp_get_post_revision')->justReturn($revision);
    Functions\when('get_current_user_id')->justReturn(0);
    Functions\when('wp_set_current_user')->justReturn(null);
    Functions\when('rest_get_route_for_post')->justReturn('/wp/v2/posts/5');
    Functions\when('rest_do_request')->justReturn(new WP_REST_Response(['title' => 'a string', 'content' => ['raw' => 'kept']]));
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('get_the_excerpt')->justReturn('short');

    $data = (new PreviewPostResponse)->build(5, 1, '6')->get_data();

    expect($data['title'])->toBe(['rendered' => 'Draft'])
        ->and($data['content'])->toBe(['raw' => 'kept', 'rendered' => '', 'protected' => false])
        ->and($data['excerpt'])->toBe(['rendered' => 'short']);
});

it('searches with its own arguments when a filter gives nothing a query can be made of', function (mixed $filtered): void {
    Functions\when('get_post_types')->justReturn(['post' => 'post']);
    Functions\when('apply_filters')->justReturn($filtered);
    $request = new WP_REST_Request;
    $request->set_param('q', 'hello');
    WP_Query::$answer = ['posts' => null];

    $response = (new SearchRoutes(frontMapper(), new NoLanguages))->search($request);

    expect(WP_Query::$queries[0])->toMatchArray(['s' => 'hello', 'post_status' => 'publish', 'has_password' => false, 'post_type' => ['post']])
        ->and($response->get_data()['results'])->toBe([]);
})->with([null, false, 12]);

it('keeps a menu item that is not an object, or has none of its fields, out of the way', function (): void {
    foreach (['get_current_blog_id' => 1, 'get_bloginfo' => 'x', 'home_url' => 'https://cms.example.com', 'get_locale' => 'en_US', 'wp_timezone_string' => 'UTC', 'is_multisite' => false, 'get_nav_menu_locations' => [], 'get_registered_nav_menus' => ['top' => ['not text']], 'get_post_types' => [], 'get_taxonomies' => []] as $function => $value) {
        Functions\when($function)->justReturn($value);
    }
    Functions\when('get_option')->justReturn(['damaged']);
    Functions\when('wp_get_nav_menus')->justReturn([(object) ['term_id' => 2, 'slug' => 'main', 'name' => 'Main']]);
    Functions\when('wp_get_nav_menu_items')->justReturn(['junk', (object) ['ID' => 4, 'title' => ['x']]]);

    $front = Origin::fromUrl('https://www.example.com');
    $manifest = (new SiteManifest(frontMapper(), $front, new NoLanguages))->build();

    expect($manifest['front_page'])->toBe(['show_on_front' => '', 'page_on_front' => 0, 'page_for_posts' => 0, 'posts_per_page' => 0])
        ->and($manifest['permalink_structure'])->toBe('')
        ->and($manifest['locations'])->toBe([['location' => 'top', 'description' => '', 'menu' => null]])
        ->and($manifest['menus'][0]['items'])->toHaveCount(1)
        ->and($manifest['menus'][0]['items'][0])->toMatchArray(['id' => 4, 'title' => '', 'classes' => [], 'object_id' => 0]);
});

it('refuses a token option given as a list, and creates nothing', function (string $option): void {
    $store = new InMemoryTokenStore;
    $output = messages();

    (new TokenCommand(new TokenService($store), static fn (): int => 7, static fn (): int => 1, $output))->create(['7'], [$option => ['/wp/v2/posts']]);

    expect($output->all)->toBe(['error: --'.$option.' takes a single value.'])
        ->and($store->tokens)->toBe([]);
})->with(['name', 'scopes', 'routes', 'expires']);

it('refuses a grace period given as a list, and rotates nothing', function (): void {
    $store = new InMemoryTokenStore;
    $service = new TokenService($store);
    $issued = $service->create(7, 'a', [], []);
    $output = messages();

    (new TokenCommand($service, static fn (): int => 7, static fn (): int => 1, $output))->rotate(['7', $issued->token->id], ['grace' => ['1h']]);

    expect($output->all)->toBe(['error: --grace takes a single value.'])
        ->and($store->tokens)->toHaveCount(1);
});

it('refuses a namespace list it cannot read, and a GraphQL schema that is not text', function (): void {
    $output = messages();
    $command = new SchemaCommand(static fn (): array => [], static fn (): array => ['title' => 't', 'version' => '1', 'server' => 's'], static fn (): array => ['not SDL'], $output, static fn (): bool => true);

    $command->openapi([], ['namespace' => ['wp/v2']]);
    $command->graphql();

    expect($output->all)->toBe(['error: --namespace takes a comma-separated list.', 'error: The GraphQL schema could not be printed: WPGraphQL gave no text.']);
});

it('leaves out of the OpenAPI document what is not a route or an endpoint', function (): void {
    $document = (new OpenApiBuilder('t', '1', 'https://cms.example.com'))->build([
        '/a' => 'not a list',
        '/b' => ['not an endpoint', ['methods' => 12]],
        '/c' => [['methods' => ['GET' => true], 'args' => ['q' => ['type' => [['x'], 'null', 'integer']]]]],
    ]);

    expect(array_keys($document['paths']))->toBe(['/c'])
        ->and($document['paths']['/c']['get']['parameters'][0]['schema'])->toBe(['type' => 'string']);
});

it('skips a breadcrumb of the structured data that is not a node', function (): void {
    $seo = SeoMapper::yoast(['schema' => ['@graph' => [['@type' => 'BreadcrumbList', 'itemListElement' => ['junk', ['name' => 'Home', 'item' => 'https://cms.example.com/']]]]]]);

    expect($seo->breadcrumbs)->toBe([['label' => 'Home', 'url' => 'https://cms.example.com/']]);
});
