<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Revalidation;

use Pollora\Headless\Domain\Revalidation\DeliveryResult;
use Pollora\Headless\Domain\Revalidation\Target;
use Pollora\Headless\Domain\Revalidation\Transport;

/**
 * Sends the notification with WordPress's HTTP API.
 *
 * The address comes from the settings an administrator wrote, and a front end often runs on localhost in
 * development: this is `wp_remote_post`, which allows it, not `wp_safe_remote_post`, which refuses a
 * private address. It does not follow redirects, so a notification cannot be sent on to another host.
 */
final class WpHttpTransport implements Transport
{
    public function send(Target $target, string $body, array $headers): DeliveryResult
    {
        $response = wp_remote_post($target->url, [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'redirection' => 0,
            'user-agent' => 'Pollora-Headless/1',
        ]);

        if ($response instanceof \WP_Error) {
            return new DeliveryResult(false, 0, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return new DeliveryResult($status >= 200 && $status < 300, $status);
    }
}
