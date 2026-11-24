<?php

declare(strict_types=1);

/**
 * Proves the suite runs against a real WordPress and not against doubles.
 */
it('has loaded WordPress', function (): void {
    expect(function_exists('add_action'))->toBeTrue()
        ->and(defined('ABSPATH'))->toBeTrue()
        ->and(is_blog_installed())->toBeTrue();
});
