<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Pollora\HeadlessServiceProvider;
use Pollora\Headless\Infrastructure\WordPress\Bootstrap;
use Pollora\Headless\Infrastructure\WordPress\ChangeWatcher;
use Pollora\Headless\Infrastructure\WordPress\ManifestRoutes;
use Pollora\Headless\Infrastructure\WordPress\MediaCdn;
use Pollora\Headless\Infrastructure\WordPress\Module;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\RevalidationRunner;
use Pollora\Headless\Infrastructure\WordPress\SearchRoutes;
use Pollora\Headless\Infrastructure\WordPress\TemplateRedirectGate;

/*
 * How the package starts: the service provider of a Pollora project, and the modules a multilingual site gets.
 */

afterEach(function (): void {
    WP_Query::$answer = [];
    WP_Query::$queries = [];
});

/**
 * A Pollora application: its configuration, its hook services, and the hooks they were given.
 *
 * @param  array<string, mixed>  $values  the configuration
 * @param  list<string>  $unbound  the services the application does not have
 */
function application(array $values, array $unbound = []): object
{
    return new class($values, $unbound)
    {
        public object $config;

        /** @var ArrayObject<int, string> */
        public ArrayObject $hooks;

        public function __construct(array $values, private array $unbound)
        {
            $this->hooks = new ArrayObject;
            $this->config = new class($values)
            {
                public function __construct(public array $values) {}

                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->values[$key] ?? $default;
                }

                public function set(string $key, mixed $value): void
                {
                    $this->values[$key] = $value;
                }
            };
        }

        public function configPath(string $file): string
        {
            return '/project/config/'.$file;
        }

        public function bound(string $abstract): bool
        {
            return ! in_array($abstract, $this->unbound, true);
        }

        public function make(string $abstract): object
        {
            if ($abstract === 'config') {
                return $this->config;
            }

            return new class(str_ends_with($abstract, 'Action') ? 'action' : 'filter', $this->hooks)
            {
                public function __construct(private string $kind, private ArrayObject $hooks) {}

                public function add(string $hook): void
                {
                    $this->hooks->append($this->kind.' '.$hook);
                }
            };
        }
    };
}

it('leaves a cached configuration as it is', function (): void {
    $app = new class implements CachesConfiguration
    {
        public function configurationIsCached(): bool
        {
            return true;
        }

        public function getCachedConfigPath(): string
        {
            return '/cache';
        }

        public function getCachedServicesPath(): string
        {
            return '/cache';
        }

        public function make(string $abstract): never
        {
            throw new RuntimeException('the cached configuration was read to be merged again');
        }
    };

    (new HeadlessServiceProvider($app))->register();

    expect($app->configurationIsCached())->toBeTrue();
});

it('publishes its configuration and locks the front a project configured', function (): void {
    Functions\when('wp_salt')->justReturn('salt');
    $app = application(['pollora.headless' => ['frontend_url' => 'https://www.example.com'], 'app.url' => 'https://cms.example.com']);

    (new HeadlessServiceProvider($app))->boot();

    expect(ServiceProvider::pathsToPublish(HeadlessServiceProvider::class, 'headless-config'))->toBe([dirname(__DIR__, 3).'/config/headless.php' => '/project/config/headless.php'])
        ->and($app->hooks->getArrayCopy())->toContain('action template_redirect', 'filter rest_pre_dispatch', 'filter determine_current_user', 'filter preview_post_link');
});

it('attaches nothing without the hook services of Pollora', function (string $missing): void {
    $app = application(['pollora.headless' => ['frontend_url' => 'https://www.example.com']], [$missing]);

    (new HeadlessServiceProvider($app))->boot();

    expect($app->hooks->getArrayCopy())->toBe([]);
})->with(['Pollora\\Hook\\Domain\\Contract\\Action', 'Pollora\\Hook\\Domain\\Contract\\Filter']);

function frenchHost(): Languages
{
    return new class implements Languages
    {
        public function plugin(): string
        {
            return 'polylang';
        }

        public function isActive(): bool
        {
            return true;
        }

        public function all(): array
        {
            return ['fr'];
        }

        public function current(): ?string
        {
            return null;
        }

        public function ofPost(int $postId): ?string
        {
            return 'fr';
        }

        public function translations(int $postId): array
        {
            return [];
        }

        public function homeUrl(string $code): ?string
        {
            return 'https://cms.example.com/';
        }
    };
}

/**
 * @template T of Module
 *
 * @param  list<Module>  $modules
 * @param  class-string<T>  $class
 * @return T
 */
function moduleOf(array $modules, string $class): Module
{
    return array_values(array_filter($modules, static fn (Module $module): bool => $module instanceof $class))[0];
}

/**
 * @param  array<string, mixed>  $more
 * @return list<Module>
 */
function multilingualModules(array $more = []): array
{
    Functions\when('wp_salt')->justReturn('salt');
    Functions\when('wp_upload_dir')->justReturn(['baseurl' => 'https://cms.example.com/wp-content/uploads']);

    return Bootstrap::modules(Settings::fromArray($more + ['frontend_url' => 'https://www.example.com', 'frontend_urls' => ['fr' => 'https://www.example.fr']], 'https://cms.example.com'), frenchHost());
}

it('gives the search of a multilingual site the front end of each language', function (): void {
    $modules = multilingualModules(['per_page_max' => 5]);
    Functions\when('get_post_types')->justReturn(['post' => 'post']);
    Functions\when('apply_filters')->returnArg(2);
    foreach (['wp_strip_all_tags', 'strip_shortcodes', 'wp_trim_words'] as $function) {
        Functions\when($function)->returnArg();
    }
    Functions\when('get_the_title')->justReturn('t');
    Functions\when('get_permalink')->justReturn('https://cms.example.com/a/');
    Functions\when('get_post_time')->justReturn('2026-01-01T00:00:00+00:00');
    Functions\when('get_post_thumbnail_id')->justReturn(false);
    WP_Query::$answer = ['posts' => [new WP_Post(['ID' => 1])]];
    $request = new WP_REST_Request;
    $request->set_param('q', 'hello');
    $request->set_param('per_page', 40);

    $data = moduleOf($modules, SearchRoutes::class)->search($request)->get_data();

    expect($data['results'][0]['url'])->toBe('https://www.example.fr/a/')
        ->and($data['per_page'])->toBe(5)
        ->and(moduleOf($modules, ManifestRoutes::class))->toBeInstanceOf(ManifestRoutes::class);
});

it('serves the uploads from the CDN the settings name', function (): void {
    Functions\when('is_admin')->justReturn(false);

    $modules = multilingualModules(['media' => ['cdn_url' => 'https://cdn.example.com']]);

    expect(moduleOf($modules, MediaCdn::class)->url('https://cms.example.com/wp-content/uploads/a.jpg'))->toBe('https://cdn.example.com/a.jpg');
});

it('keeps the gate last, after every module that answers', function (): void {
    $modules = multilingualModules();

    expect($modules[count($modules) - 1])->toBeInstanceOf(TemplateRedirectGate::class);
});

it('serves the redirects of a multilingual site whose manifest is off', function (): void {
    Functions\when('get_option')->justReturn([['from' => '/old', 'to' => '/new']]);
    Functions\when('get_transient')->justReturn([['from' => '/before', 'to' => '/after', 'source' => 'old-slug']]);
    Functions\when('rest_ensure_response')->alias(static fn (array $data): WP_REST_Response => new WP_REST_Response($data));

    $modules = multilingualModules(['manifest' => ['enabled' => false]]);
    $data = moduleOf($modules, RedirectRoutes::class)->all()->get_data();

    expect(array_column($data['redirects'], 'from'))->toBe(['/old', '/before'])
        ->and(array_filter($modules, static fn (Module $module): bool => $module instanceof ManifestRoutes))->toBe([]);
});

it('watches the changes only when somewhere is to be told', function (array $targets, bool $watching): void {
    $classes = array_map(get_class(...), multilingualModules(['revalidation' => ['targets' => $targets]]));

    expect(in_array(ChangeWatcher::class, $classes, true))->toBe($watching)
        ->and(in_array(RevalidationRunner::class, $classes, true))->toBe($watching);
})->with([
    'a target' => [[['url' => 'https://www.example.com/hook', 'secret' => 'shared']], true],
    'no target' => [[], false],
]);

it('serves the redirects of a site with one language', function (): void {
    Functions\when('wp_salt')->justReturn('salt');

    $modules = Bootstrap::modules(Settings::fromArray(['frontend_url' => 'https://www.example.com', 'manifest' => ['enabled' => false]], 'https://cms.example.com'));

    expect(moduleOf($modules, RedirectRoutes::class))->toBeInstanceOf(RedirectRoutes::class);
});
