<?php

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pollora\Headless\Domain\Form\Form;
use Pollora\Headless\Domain\Form\FormField;
use Pollora\Headless\Domain\Form\FormProvider;
use Pollora\Headless\Infrastructure\WordPress\FormRoutes;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

function responseDouble(): void
{
    Functions\when('rest_ensure_response')->alias(static function (array $data): WP_REST_Response {
        $response = Mockery::mock('WP_REST_Response');
        $response->shouldReceive('get_data')->andReturn($data);

        return $response;
    });
}

function sampleForm(): Form
{
    return new Form(
        'cf7',
        '12',
        'Contact',
        [new FormField('your-email', 'email', true), new FormField('topics', 'checkbox', false, true, [['value' => 'a', 'label' => 'A']])],
        'https://cms.example.com/wp-json/contact-form-7/v1/contact-forms/12/feedback',
        'multipart/form-data',
        ['_wpcf7_unit_tag' => 'wpcf7-f12-o1'],
    );
}

function sampleProvider(): FormProvider
{
    return new class implements FormProvider
    {
        public function name(): string
        {
            return 'cf7';
        }

        public function forms(): array
        {
            return [sampleForm()];
        }

        public function find(string $id): ?Form
        {
            return $id === '12' ? sampleForm() : null;
        }
    };
}

it('describes a form and how to send it', function (): void {
    $form = sampleForm()->toArray();

    expect($form['provider'])->toBe('cf7')
        ->and($form['id'])->toBe('12')
        ->and($form['fields'][0])->toBe(['name' => 'your-email', 'type' => 'email', 'required' => true, 'multiple' => false, 'options' => []])
        ->and($form['fields'][1]['multiple'])->toBeTrue()
        ->and($form['submit']['method'])->toBe('POST')
        ->and($form['submit']['content_type'])->toBe('multipart/form-data')
        ->and((array) $form['submit']['extra'])->toBe(['_wpcf7_unit_tag' => 'wpcf7-f12-o1'])
        ->and(sampleForm()->summary())->toBe(['provider' => 'cf7', 'id' => '12', 'title' => 'Contact']);
});

it('keeps an empty extra as a JSON object', function (): void {
    $form = new Form('x', '1', 't', [], 'https://x.test', 'application/json');

    expect(json_encode($form->toArray()['submit']))->toContain('"extra":{}');
});

it('registers its two routes on the REST API', function (): void {
    Brain\Monkey\Actions\expectAdded('rest_api_init')->once();
    $routes = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route) use (&$routes): void {
        $routes[] = $namespace.$route;
    });

    $module = new FormRoutes([]);
    $module->register(new WordPressHooks);
    $module->routes();

    expect($routes)->toBe(['headless/v1/forms', 'headless/v1/forms/(?P<provider>[a-z0-9_-]+)/(?P<id>[A-Za-z0-9_-]+)']);
});

it('lists the forms of every provider, and lets a filter add providers', function (): void {
    responseDouble();
    Filters\expectApplied('headless/forms/providers')->once()->andReturnUsing(static fn (array $providers): array => [...$providers, 'not a provider']);

    expect((new FormRoutes([sampleProvider()]))->index()->get_data())->toBe(['forms' => [['provider' => 'cf7', 'id' => '12', 'title' => 'Contact']]]);
});

it('gives the fields of one form, and a 404 for one that is not there', function (): void {
    responseDouble();
    Functions\when('apply_filters')->alias(static fn (string $hook, mixed $value): mixed => $value);

    $module = new FormRoutes([sampleProvider()]);
    $request = static function (string $provider, string $id): WP_REST_Request {
        $request = Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->with('provider')->andReturn($provider);
        $request->shouldReceive('get_param')->with('id')->andReturn($id);

        return $request;
    };

    expect($module->show($request('cf7', '12'))->get_data()['title'])->toBe('Contact')
        ->and($module->show($request('cf7', '99')))->toBeInstanceOf(WP_Error::class)
        ->and($module->show($request('other', '12')))->toBeInstanceOf(WP_Error::class);
});
