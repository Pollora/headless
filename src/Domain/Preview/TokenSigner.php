<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Preview;

use JsonException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenExpiredException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;

/**
 * Signs and checks preview tokens: `base64url(payload).base64url(HMAC-SHA256(payload))`.
 *
 * The token carries its claims, so nothing is looked up to read it; the one-time rule is kept apart,
 * by whoever stores the token's ID. The signature is compared with hash_equals.
 */
final class TokenSigner
{
    public static function sign(PreviewClaims $claims, string $key): string
    {
        $payload = self::encode(json_encode([
            't' => $claims->type->value,
            'u' => $claims->userId,
            'p' => $claims->postId,
            's' => $claims->siteId,
            'a' => $claims->audience,
            'e' => $claims->expiresAt,
            'j' => $claims->id,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.self::encode(hash_hmac('sha256', $payload, $key, true));
    }

    /**
     * @throws PreviewTokenInvalidException when the token is malformed, forged or of another type
     * @throws PreviewTokenExpiredException when it is past its expiry
     */
    public static function verify(string $token, string $key, int $now, PreviewTokenType $expected): PreviewClaims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw PreviewTokenInvalidException::raise();
        }

        if (! hash_equals(self::encode(hash_hmac('sha256', $parts[0], $key, true)), $parts[1])) {
            throw PreviewTokenInvalidException::raise();
        }

        try {
            $data = json_decode((string) self::decode($parts[0]), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PreviewTokenInvalidException::raise();
        }

        $type = is_array($data) && is_string($data['t'] ?? null) ? PreviewTokenType::tryFrom($data['t']) : null;

        if (! is_array($data) || $type !== $expected
            || ! is_int($data['u'] ?? null) || ! is_int($data['p'] ?? null) || ! is_int($data['s'] ?? null)
            || ! is_string($data['a'] ?? null) || ! is_int($data['e'] ?? null) || ! is_string($data['j'] ?? null) || $data['j'] === '') {
            throw PreviewTokenInvalidException::raise();
        }

        if ($data['e'] <= $now) {
            throw PreviewTokenExpiredException::raise();
        }

        return new PreviewClaims($type, $data['u'], $data['p'], $data['s'], $data['a'], $data['e'], $data['j']);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
