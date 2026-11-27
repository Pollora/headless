<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Form\Form;
use Pollora\Headless\Domain\Form\FormProvider;
use Pollora\Headless\Domain\Scalar;

/**
 * `GET /wp-json/headless/v1/forms` and `/forms/{provider}/{id}`: the forms of the site and how to send them.
 *
 * The site's own form plugin takes the submission (see FormProvider). A plugin that is not supported can be added
 * with `add_filter('headless/forms/providers', fn (array $providers) => [...$providers, new MyProvider])`.
 */
final readonly class FormRoutes implements Module
{
    /**
     * @param  list<FormProvider>  $providers
     */
    public function __construct(private array $providers) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(ManifestRoutes::NAMESPACE, '/forms', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
            // Public on purpose: a form is what a visitor sees on the page
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(ManifestRoutes::NAMESPACE, '/forms/(?P<provider>[a-z0-9_-]+)/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function index(): \WP_REST_Response
    {
        $forms = [];

        foreach ($this->providers() as $provider) {
            foreach ($provider->forms() as $form) {
                $forms[] = $form->summary();
            }
        }

        return rest_ensure_response(['forms' => $forms]);
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        foreach ($this->providers() as $provider) {
            if ($provider->name() !== $request->get_param('provider')) {
                continue;
            }

            $form = $provider->find(Scalar::string($request->get_param('id')));

            if ($form instanceof Form) {
                return rest_ensure_response($form->toArray());
            }
        }

        return new \WP_Error('headless_form_not_found', 'This form does not exist.', ['status' => 404]);
    }

    /**
     * @return list<FormProvider>
     */
    private function providers(): array
    {
        $providers = apply_filters('headless/forms/providers', $this->providers);

        return array_values(array_filter(is_array($providers) ? $providers : [], static fn (mixed $provider): bool => $provider instanceof FormProvider));
    }
}
