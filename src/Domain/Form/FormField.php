<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Form;

/**
 * One input of a form, as a client needs it to draw the input and send the value.
 */
final readonly class FormField
{
    /**
     * @param  string  $type  text, email, url, tel, number, date, textarea, select, checkbox, radio, acceptance, quiz or file
     * @param  list<array{value: string, label: string}>  $options  the choices of a select, a checkbox group or a radio group
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required = false,
        public bool $multiple = false,
        public array $options = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'multiple' => $this->multiple,
            'options' => $this->options,
        ];
    }
}
