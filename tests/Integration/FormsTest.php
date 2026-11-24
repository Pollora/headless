<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\Form\ContactForm7Provider;
use Pollora\Headless\Infrastructure\WordPress\FormRoutes;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * The forms endpoints against a real Contact Form 7, installed by the CI job.
 */
function formsServer(): void
{
    (new FormRoutes([new ContactForm7Provider]))->register(new WordPressHooks);

    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
}

function makeContactForm(): int
{
    $form = WPCF7_ContactForm::get_template(['title' => 'Contact us']);
    $form->set_properties(['form' => <<<'FORM'
        [text* your-name]
        [email* your-email]
        [tel your-phone]
        [select topic "Sales" "Support"]
        [checkbox colours use_label_element "Red" "Blue"]
        [radio plan "Free" "Paid"]
        [acceptance consent]
        [file attachment]
        [textarea message]
        [submit "Send"]
        FORM]);

    return $form->save();
}

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

it('runs against a real Contact Form 7', function (): void {
    expect(ContactForm7Provider::isAvailable())->toBeTrue();
});

it('lists the forms of the site', function (): void {
    formsServer();
    $id = makeContactForm();

    $data = rest_do_request(new WP_REST_Request('GET', '/headless/v1/forms'))->get_data();
    wp_delete_post($id, true);

    expect($data['forms'])->toContain(['provider' => 'cf7', 'id' => (string) $id, 'title' => 'Contact us']);
});

it('describes the fields of a form and where to send them', function (): void {
    formsServer();
    $id = makeContactForm();

    $response = rest_do_request(new WP_REST_Request('GET', "/headless/v1/forms/cf7/$id"));
    $data = $response->get_data();
    wp_delete_post($id, true);
    $fields = array_column($data['fields'], null, 'name');

    expect($response->get_status())->toBe(200)
        ->and(array_keys($fields))->toBe(['your-name', 'your-email', 'your-phone', 'topic', 'colours', 'plan', 'consent', 'attachment', 'message'])
        ->and($fields['your-name'])->toMatchArray(['type' => 'text', 'required' => true])
        ->and($fields['your-email'])->toMatchArray(['type' => 'email', 'required' => true])
        ->and($fields['your-phone'])->toMatchArray(['type' => 'tel', 'required' => false])
        ->and($fields['topic']['options'])->toBe([['value' => 'Sales', 'label' => 'Sales'], ['value' => 'Support', 'label' => 'Support']])
        ->and($fields['colours'])->toMatchArray(['type' => 'checkbox', 'multiple' => true])
        ->and($fields['plan'])->toMatchArray(['type' => 'radio', 'multiple' => false])
        ->and($fields['consent']['type'])->toBe('acceptance')
        ->and($fields['attachment']['type'])->toBe('file')
        ->and($data['submit']['method'])->toBe('POST')
        ->and($data['submit']['content_type'])->toBe('multipart/form-data')
        ->and($data['submit']['url'])->toContain("contact-form-7/v1/contact-forms/$id/feedback")
        ->and($data['submit']['extra']->_wpcf7_unit_tag)->toBe("wpcf7-f$id-o1");
});

it('answers 404 for a form that does not exist', function (): void {
    formsServer();

    expect(rest_do_request(new WP_REST_Request('GET', '/headless/v1/forms/cf7/999999'))->get_status())->toBe(404)
        ->and(rest_do_request(new WP_REST_Request('GET', '/headless/v1/forms/cf7/abc'))->get_status())->toBe(404)
        ->and(rest_do_request(new WP_REST_Request('GET', '/headless/v1/forms/other/1'))->get_status())->toBe(404);
});
