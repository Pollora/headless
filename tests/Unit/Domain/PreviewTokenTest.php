<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Exceptions\PreviewTokenExpiredException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Preview\PreviewClaims;
use Pollora\Headless\Domain\Preview\PreviewTokenType;
use Pollora\Headless\Domain\Preview\TokenSigner;

function previewClaims(PreviewTokenType $type = PreviewTokenType::Link, int $expires = 2000): PreviewClaims
{
    return new PreviewClaims($type, 7, 42, 1, 'https://www.example.com', $expires, 'abc123');
}

it('signs claims and reads them back', function (): void {
    $token = TokenSigner::sign(previewClaims(), 'key');

    expect(TokenSigner::verify($token, 'key', 1000, PreviewTokenType::Link))->toEqual(previewClaims());
});

it('refuses a token signed with another key, or altered', function (): void {
    $token = TokenSigner::sign(previewClaims(), 'key');
    [$payload, $signature] = explode('.', $token);
    $other = TokenSigner::sign(new PreviewClaims(PreviewTokenType::Link, 1, 99, 1, 'https://www.example.com', 2000, 'zzz'), 'key');

    expect(fn () => TokenSigner::verify($token, 'other key', 1000, PreviewTokenType::Link))->toThrow(PreviewTokenInvalidException::class)
        ->and(fn () => TokenSigner::verify($payload.'.'.strrev($signature), 'key', 1000, PreviewTokenType::Link))->toThrow(PreviewTokenInvalidException::class)
        ->and(fn () => TokenSigner::verify(explode('.', $other)[0].'.'.$signature, 'key', 1000, PreviewTokenType::Link))->toThrow(PreviewTokenInvalidException::class);
});

it('refuses what is not a token', function (string $value): void {
    TokenSigner::verify($value, 'key', 1000, PreviewTokenType::Link);
})->throws(PreviewTokenInvalidException::class)->with(['', 'abc', 'a.b.c', '.sig', 'payload.', 'not.signed']);

it('refuses a token of the other type: a link cannot be used as a session', function (): void {
    $link = TokenSigner::sign(previewClaims(PreviewTokenType::Link), 'key');

    TokenSigner::verify($link, 'key', 1000, PreviewTokenType::Session);
})->throws(PreviewTokenInvalidException::class);

it('refuses a token at its expiry, and after', function (int $now): void {
    TokenSigner::verify(TokenSigner::sign(previewClaims(expires: 2000), 'key'), 'key', $now, PreviewTokenType::Link);
})->throws(PreviewTokenExpiredException::class)->with([2000, 2001, 99999]);

it('refuses a signed payload that is not claims', function (): void {
    $payload = rtrim(strtr(base64_encode('{"t":"link","u":"7"}'), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, 'key', true)), '+/', '-_'), '=');
    $notJson = rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=');
    $notJsonSigned = $notJson.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $notJson, 'key', true)), '+/', '-_'), '=');

    expect(fn () => TokenSigner::verify($payload.'.'.$signature, 'key', 1000, PreviewTokenType::Link))->toThrow(PreviewTokenInvalidException::class)
        ->and(fn () => TokenSigner::verify($notJsonSigned, 'key', 1000, PreviewTokenType::Link))->toThrow(PreviewTokenInvalidException::class);
});
