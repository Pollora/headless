<?php

declare(strict_types=1);

namespace Pollora\Headless\Tests\Fixtures;

/**
 * A stand-in for WP_Error in the unit suite (aliased in tests/Pest.php); the integration suite uses the real one.
 */
final class FakeWpError
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly string $code = '', private readonly string $message = '', private readonly array $data = []) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_error_data(): array
    {
        return $this->data;
    }
}
