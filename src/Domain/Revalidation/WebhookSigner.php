<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Signs a notification so a front end knows it comes from this WordPress, and is not a replay.
 *
 * `X-Headless-Signature: sha256=<hex>` is HMAC-SHA256, keyed with the target's secret, of
 * `<timestamp>.<raw body>`. The timestamp is in `X-Headless-Timestamp`: a receiver rejects a request
 * older than five minutes, and compares the signature in constant time.
 */
final class WebhookSigner
{
    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $body, string $secret, int $timestamp, string $deliveryId, int $attempt): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Headless-Signature' => self::signature($body, $secret, $timestamp),
            'X-Headless-Timestamp' => (string) $timestamp,
            'X-Headless-Delivery' => $deliveryId,
            'X-Headless-Attempt' => (string) $attempt,
        ];
    }

    /** What a receiver does, here for the tests and as the reference of the contract. */
    public static function verify(string $body, string $secret, int $timestamp, string $signature, int $now, int $tolerance = 300): bool
    {
        return abs($now - $timestamp) <= $tolerance && hash_equals(self::signature($body, $secret, $timestamp), $signature);
    }
}
