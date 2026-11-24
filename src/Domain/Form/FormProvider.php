<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Form;

/**
 * A form plugin the package can describe the forms of.
 */
interface FormProvider
{
    /** The name a client sees in the address: `cf7`. */
    public function name(): string;

    /**
     * @return list<Form>
     */
    public function forms(): array;

    public function find(string $id): ?Form;
}
