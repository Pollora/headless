<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Form;

use Pollora\Headless\Domain\Form\Form;
use Pollora\Headless\Domain\Form\FormField;
use Pollora\Headless\Domain\Form\FormProvider;
use Pollora\Headless\Domain\Scalar;

/**
 * The forms of Contact Form 7, described from its own form tags.
 *
 * A client posts `multipart/form-data` to the plugin's REST route (`/contact-form-7/v1/contact-forms/{id}/feedback`),
 * with the fields below and the `extra` ones: Contact Form 7 refuses a submission without its unit tag.
 * A checkbox group, and a select that takes several values, are posted as `name[]`.
 */
final class ContactForm7Provider implements FormProvider
{
    /** Tags that are not inputs: the button, the answer area, the counters and the bot checks */
    private const array SKIPPED = ['submit', 'response', 'count', 'recaptcha', 'turnstile', 'captchac', 'captchar', 'reflection', 'hidden'];

    private const array TYPES = ['text', 'email', 'url', 'tel', 'number', 'range', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance', 'quiz', 'file'];

    public static function isAvailable(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    public function name(): string
    {
        return 'cf7';
    }

    public function forms(): array
    {
        $forms = [];

        foreach (\WPCF7_ContactForm::find(['posts_per_page' => -1]) as $form) {
            if ($form instanceof \WPCF7_ContactForm) {
                $forms[] = $this->describe($form);
            }
        }

        return $forms;
    }

    public function find(string $id): ?Form
    {
        $form = ctype_digit($id) ? \WPCF7_ContactForm::get_instance((int) $id) : null;

        return $form instanceof \WPCF7_ContactForm ? $this->describe($form) : null;
    }

    private function describe(\WPCF7_ContactForm $form): Form
    {
        $fields = [];

        foreach ($form->scan_form_tags() as $tag) {
            if (! $tag instanceof \WPCF7_FormTag) {
                continue;
            }

            $base = Scalar::string($tag->basetype);
            $name = Scalar::string($tag->name);

            if ($name === '' || in_array($base, self::SKIPPED, true) || ! in_array($base, self::TYPES, true)) {
                continue;
            }

            $multiple = $base === 'checkbox' ? ! $tag->has_option('exclusive') : ($base === 'select' && $tag->has_option('multiple'));
            $fields[] = new FormField($name, $base, $tag->is_required(), $multiple, self::options($base, $tag));
        }

        $id = Scalar::string($form->id());

        return new Form(
            $this->name(),
            $id,
            Scalar::string($form->title()),
            $fields,
            rest_url(sprintf('contact-form-7/v1/contact-forms/%s/feedback', $id)),
            'multipart/form-data',
            ['_wpcf7' => $id, '_wpcf7_locale' => Scalar::string($form->locale()), '_wpcf7_unit_tag' => sprintf('wpcf7-f%s-o1', $id)],
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function options(string $base, \WPCF7_FormTag $tag): array
    {
        if (! in_array($base, ['select', 'checkbox', 'radio'], true)) {
            return [];
        }

        $options = [];

        $labels = (array) $tag->labels;

        foreach (array_values((array) $tag->values) as $index => $value) {
            $options[] = ['value' => Scalar::string($value), 'label' => Scalar::string($labels[$index] ?? $value)];
        }

        return $options;
    }
}
