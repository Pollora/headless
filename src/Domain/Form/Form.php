<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Form;

/**
 * A form of the site and how to send it: the fields, then where and how to post them.
 *
 * The submission goes to the form plugin itself (it validates, sends the mail, keeps the entry): this
 * description only tells a client what to send.
 */
final readonly class Form
{
    /**
     * @param  list<FormField>  $fields
     * @param  array<string, string>  $extra  fields to send with the others, that the plugin needs
     */
    public function __construct(
        public string $provider,
        public string $id,
        public string $title,
        public array $fields,
        public string $submitUrl,
        public string $contentType,
        public array $extra = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return ['provider' => $this->provider, 'id' => $this->id, 'title' => $this->title];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->summary() + [
            'fields' => array_map(static fn (FormField $field): array => $field->toArray(), $this->fields),
            'submit' => ['method' => 'POST', 'url' => $this->submitUrl, 'content_type' => $this->contentType, 'extra' => (object) $this->extra],
        ];
    }
}
