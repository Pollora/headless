<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectSource;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Redirect\OldSlugRedirects;
use Pollora\Headless\Infrastructure\Redirect\OptionRedirectStore;
use Pollora\Headless\Infrastructure\Redirect\RedirectCatalog;
use Pollora\Headless\Infrastructure\WordPress\RedirectRoutes;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;
use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\RedirectCommand;

function redirectOptions(): ArrayObject
{
    $options = new ArrayObject;
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $options[$name] ?? $default);
    Functions\when('update_option')->alias(function (string $name, mixed $value) use ($options): bool {
        $options[$name] = $value;

        return true;
    });

    return $options;
}

it('keeps the rules of an editor in one option that is not autoloaded, one per source path', function (): void {
    $options = redirectOptions();
    Functions\expect('update_option')->never();
    redirectOptions();

    $store = new OptionRedirectStore;
    $store->save(new Redirect('/old/', '/new/'));
    $store->save(new Redirect('/old', '/newer/', 302));
    $store->save(new Redirect('/gone/', '', 410));

    $stored = $store->redirects();

    expect($stored)->toHaveCount(2)
        ->and(array_map(static fn (Redirect $rule): string => $rule->from.'→'.$rule->to, $stored))->toBe(['/old→/newer/', '/gone/→']);
});

it('asks for the option not to be autoloaded', function (): void {
    Functions\when('get_option')->justReturn([]);
    Functions\expect('update_option')->once()->with('headless_redirects', Mockery::type('array'), false);

    (new OptionRedirectStore)->save(new Redirect('/a', '/b'));
});

it('removes a rule, with or without its trailing slash, and says when there was none', function (): void {
    redirectOptions();
    $store = new OptionRedirectStore;
    $store->save(new Redirect('/old/', '/new/'));

    expect($store->remove('/old'))->toBeTrue()
        ->and($store->remove('/old/'))->toBeFalse()
        ->and($store->redirects())->toBe([]);
});

it('ignores what the option holds that is not a rule', function (): void {
    $options = redirectOptions();
    $options['headless_redirects'] = ['x' => 'junk', 'y' => ['from' => 'bad', 'to' => '/x'], '/ok' => ['from' => '/ok', 'to' => '/fine']];
    $store = new OptionRedirectStore;

    expect(array_map(static fn (Redirect $rule): string => $rule->from, $store->redirects()))->toBe(['/ok']);

    $options['headless_redirects'] = 'broken';

    expect($store->redirects())->toBe([]);
});

it('lists the sources in order, so that the first one wins', function (): void {
    $source = static fn (Redirect ...$rules): RedirectSource => new class($rules) implements RedirectSource
    {
        /** @param list<Redirect> $rules */
        public function __construct(private array $rules) {}

        public function redirects(): array
        {
            return $this->rules;
        }
    };

    $rules = (new RedirectCatalog([$source(new Redirect('/a', '/manual')), $source(new Redirect('/a', '/old-slug', 301, false, 'old-slug'), new Redirect('/b', '/c'))]))->rules();

    expect($rules->all())->toHaveCount(3)->and($rules->match('/a')['to'])->toBe('/manual');
});

it('registers its routes, and drops the old slugs when a post changes', function (): void {
    Actions\expectAdded('rest_api_init')->once();
    Actions\expectAdded('post_updated')->once();
    Actions\expectAdded('deleted_post')->once();
    $routes = new RedirectRoutes(new RedirectCatalog([]));
    $routes->register(new WordPressHooks);

    Functions\expect('register_rest_route')->twice()->with('headless/v1', Mockery::pattern('#^/redirects#'), Mockery::on(static fn (array $args): bool => $args['methods'] === 'GET' && $args['permission_callback'] === '__return_true'));
    $routes->routes();
});

function wpResponse(): void
{
    Functions\when('rest_ensure_response')->alias(static function (array $data): WP_REST_Response {
        $response = Mockery::mock('WP_REST_Response');
        $response->shouldReceive('get_data')->andReturn($data);

        return $response;
    });
}

it('lists the rules, and says when the list was cut', function (): void {
    wpResponse();
    $catalog = new RedirectCatalog([new class implements RedirectSource
    {
        public function redirects(): array
        {
            return [new Redirect('/a', '/b'), new Redirect('/g', '', 410, false, 'manual')];
        }
    }]);

    $data = (new RedirectRoutes($catalog))->all()->get_data();

    expect($data['count'])->toBe(2)
        ->and($data['truncated'])->toBeFalse()
        ->and($data['redirects'][0])->toBe(['from' => '/a', 'to' => '/b', 'status' => 301, 'regex' => false, 'source' => 'manual'])
        ->and($data['redirects'][1]['status'])->toBe(410);
});

it('resolves one path, with a 200 and null when it goes nowhere', function (): void {
    wpResponse();
    $routes = new RedirectRoutes(new RedirectCatalog([new class implements RedirectSource
    {
        public function redirects(): array
        {
            return [new Redirect('/blog/(.*)', '/news/$1', 308, true, 'manual')];
        }
    }]));
    $ask = static function (string $path): WP_REST_Request {
        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->with('path')->andReturn($path);

        return $request;
    };

    expect($routes->resolve($ask('/blog/hello'))->get_data())->toBe(['redirect' => ['from' => '/blog/(.*)', 'to' => '/news/hello', 'status' => 308, 'source' => 'manual']])
        ->and($routes->resolve($ask('/elsewhere'))->get_data())->toBe(['redirect' => null]);
});

it('keeps the list of old slugs for five minutes and drops it on demand', function (): void {
    $transients = new ArrayObject;
    Functions\when('get_transient')->alias(static fn (string $key): mixed => $transients[$key] ?? false);
    Functions\when('set_transient')->alias(function (string $key, mixed $value, int $seconds) use ($transients): bool {
        $transients[$key] = $value;
        expect($seconds)->toBe(300);

        return true;
    });
    Functions\when('delete_transient')->alias(function (string $key) use ($transients): bool {
        unset($transients[$key]);

        return true;
    });
    $wpdb = Mockery::mock('wpdb');
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->posts = 'wp_posts';
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_results')->once()->andReturn([(object) ['post_id' => 7, 'old_slug' => 'before'], (object) ['post_id' => 7, 'old_slug' => 'hello'], (object) ['post_id' => 99, 'old_slug' => 'ghost'], (object) ['post_id' => 7, 'old_slug' => '']]);
    $GLOBALS['wpdb'] = $wpdb;

    $post = Mockery::mock('WP_Post');
    $post->post_name = 'hello';
    Functions\when('get_post')->alias(static fn (int $id): ?WP_Post => $id === 7 ? $post : null);
    Functions\when('get_permalink')->alias(static fn (WP_Post $p): string => 'https://cms.example.com/'.$p->post_name.'/');

    $source = new OldSlugRedirects(new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com')));
    $first = $source->redirects();
    $second = $source->redirects();

    expect($first)->toHaveCount(1)
        ->and($first[0]->from)->toBe('/before/')
        ->and($first[0]->to)->toBe('/hello/')
        ->and($first[0]->source)->toBe('old-slug')
        ->and($second)->toEqual($first)
        ->and($transients)->toHaveCount(1);

    OldSlugRedirects::forget();

    expect($transients)->toHaveCount(0);
    unset($GLOBALS['wpdb']);
});

it('skips a post whose old and current addresses are the same (plain permalinks)', function (): void {
    Functions\when('get_transient')->justReturn(false);
    Functions\when('set_transient')->justReturn(true);
    $wpdb = Mockery::mock('wpdb');
    $wpdb->postmeta = 'wp_postmeta';
    $wpdb->posts = 'wp_posts';
    $wpdb->shouldReceive('prepare')->andReturn('SQL');
    $wpdb->shouldReceive('get_results')->andReturn([(object) ['post_id' => 7, 'old_slug' => 'before']]);
    $GLOBALS['wpdb'] = $wpdb;
    $post = Mockery::mock('WP_Post');
    $post->post_name = 'hello';
    Functions\when('get_post')->justReturn($post);
    Functions\when('get_permalink')->justReturn('https://cms.example.com/?p=7');

    $source = new OldSlugRedirects(new UrlMapper(Origin::fromUrl('https://cms.example.com'), Origin::fromUrl('https://www.example.com')));

    expect($source->redirects())->toBe([]);
    unset($GLOBALS['wpdb']);
});

function redirectOutput(): object
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $messages = [];

        public function line(string $message): void
        {
            $this->messages[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->messages[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->messages[] = 'error: '.$message;
        }
    };
}

function redirectCommand(Output $output): RedirectCommand
{
    redirectOptions();
    $store = new OptionRedirectStore;

    return new RedirectCommand($store, fn (): RedirectCatalog => new RedirectCatalog([$store]), $output);
}

it('adds, lists, tests and removes a redirect from the command line', function (): void {
    $output = redirectOutput();
    $command = redirectCommand($output);

    $command->add(['/old/', '/new/']);
    $command->add(['/blog/(.*)', '/news/$1'], ['regex' => true, 'status' => '308']);
    $command->add(['/discontinued/'], ['status' => '410']);
    $command->list_();
    $command->test(['/old']);
    $command->test(['/blog/hello']);
    $command->test(['/discontinued/']);
    $command->test(['/nothing']);
    $command->remove(['/old/']);
    $command->remove(['/old/']);

    expect($output->messages)->toBe([
        'success: /old/ → /new/ (301).',
        'success: /blog/(.*) → /news/$1 (308).',
        'success: /discontinued/ → gone (410).',
        'line: manual      /old/ → /new/ (301)',
        'line: manual    ~ /blog/(.*) → /news/$1 (308)',
        'line: manual      /discontinued/ → gone (410)',
        'success: /old → /new/ (301, from the manual rule /old/).',
        'success: /blog/hello → /news/hello (308, from the manual rule /blog/(.*)).',
        'success: /discontinued/ → gone (410, from the manual rule /discontinued/).',
        'line: /nothing is not redirected.',
        'success: Redirect removed.',
        'error: No redirect for "/old/" among the ones you manage.',
    ]);
});

it('says what is wrong with a rule, and when there is none to list', function (): void {
    $output = redirectOutput();
    $command = redirectCommand($output);

    $command->list_();
    $command->add(['old', '/new']);
    $command->add(['/a', '/a']);
    $command->add(['/a', '/b'], ['status' => '200']);
    $command->add([]);

    expect($output->messages[0])->toBe('line: No redirect.')
        ->and($output->messages[1])->toContain('error:')->toContain('is not a path to redirect')
        ->and($output->messages[2])->toContain('redirects to itself')
        ->and($output->messages[3])->toContain('is not a redirect status')
        ->and($output->messages[4])->toContain('error:');
});

it('reads the redirect settings', function (): void {
    $on = Settings::fromArray(['frontend_url' => 'https://www.example.com'], 'https://cms.example.com');
    $off = Settings::fromArray(['frontend_url' => 'https://www.example.com', 'redirects' => ['enabled' => false, 'old_slugs' => false]], 'https://cms.example.com');

    expect($on->redirects)->toBeTrue()->and($on->oldSlugRedirects)->toBeTrue()
        ->and($off->redirects)->toBeFalse()->and($off->oldSlugRedirects)->toBeFalse()
        ->and(Settings::fromArray([], 'https://cms.example.com')->redirects)->toBeFalse();
});
