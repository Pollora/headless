<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Cors\CorsPolicy;
use Pollora\Headless\Domain\Cors\CorsRequest;

function cors(array $origins = ['https://www.example.com'], bool $credentials = false): CorsPolicy
{
    return new CorsPolicy($origins, $credentials);
}

it('answers an allowed origin with that origin, never with a wildcard', function (): void {
    $decision = cors()->decide(new CorsRequest('https://www.example.com'));

    expect($decision->allowed)->toBeTrue()
        ->and($decision->headers['Access-Control-Allow-Origin'])->toBe('https://www.example.com')
        ->and($decision->headers['Vary'])->toBe('Origin')
        ->and($decision->headers)->not->toHaveKey('Access-Control-Allow-Credentials')
        ->and($decision->headers)->not->toHaveKey('Access-Control-Allow-Methods');
});

it('says nothing but Vary to an origin it does not know, or to no origin', function (?string $origin): void {
    $decision = cors()->decide(new CorsRequest($origin));

    expect($decision->allowed)->toBeFalse()->and($decision->headers)->toBe(['Vary' => 'Origin']);
})->with([
    'other site' => 'https://evil.example.org',
    'the null origin' => 'null',
    'another scheme' => 'http://www.example.com',
    'another port' => 'https://www.example.com:8443',
    'a sub-domain' => 'https://api.www.example.com',
    'a suffix trick' => 'https://www.example.com.evil.test',
    'a path' => 'https://www.example.com/path',
    'none' => null,
]);

it('compares origins without regard to case', function (): void {
    expect(cors(['https://WWW.Example.com'])->allows('https://www.EXAMPLE.com'))->toBeTrue();
});

it('allows only with credentials when the policy says so', function (): void {
    expect(cors(credentials: true)->decide(new CorsRequest('https://www.example.com'))->headers['Access-Control-Allow-Credentials'])->toBe('true');
});

it('matches a sub-domain pattern on a dot boundary, with the same scheme and port', function (string $origin, bool $expected): void {
    expect(cors(['https://*.example.com'])->allows($origin))->toBe($expected);
})->with([
    ['https://app.example.com', true],
    ['https://a.b.example.com', true],
    ['https://example.com', false],
    ['https://evilexample.com', false],
    ['http://app.example.com', false],
    ['https://app.example.com:8443', false],
]);

it('answers a preflight with methods, headers and a max age', function (): void {
    $decision = cors()->decide(new CorsRequest('https://www.example.com', 'OPTIONS', 'POST', 'content-type, authorization'));

    expect($decision->headers['Access-Control-Allow-Methods'])->toContain('POST')
        ->and($decision->headers['Access-Control-Allow-Headers'])->toBe('content-type, authorization')
        ->and($decision->headers['Access-Control-Max-Age'])->toBe('600');
});

it('lists its own headers when a preflight asks for one it does not allow', function (): void {
    $decision = cors()->decide(new CorsRequest('https://www.example.com', 'OPTIONS', 'GET', 'x-evil'));

    expect($decision->headers['Access-Control-Allow-Headers'])->toBe('Authorization, Content-Type, Accept, X-WP-Nonce, X-HTTP-Method-Override');
});

it('knows what a preflight is', function (): void {
    expect((new CorsRequest('https://a.test', 'OPTIONS', 'GET'))->isPreflight())->toBeTrue()
        ->and((new CorsRequest('https://a.test', 'OPTIONS'))->isPreflight())->toBeFalse()
        ->and((new CorsRequest('https://a.test', 'GET', 'GET'))->isPreflight())->toBeFalse();
});

it('has origins only when some are listed', function (): void {
    expect(cors()->hasOrigins())->toBeTrue()->and(cors([])->hasOrigins())->toBeFalse();
});
