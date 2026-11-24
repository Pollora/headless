<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Language\LanguageUrls;

/**
 * @param  array<string, string>  $homes  WordPress home URL by language
 */
function fakeLanguages(array $homes, bool $active = true): Languages
{
    return new readonly class($homes, $active) implements Languages
    {
        /** @param array<string, string> $homes */
        public function __construct(private array $homes, private bool $active) {}

        public function plugin(): string
        {
            return 'fake';
        }

        public function isActive(): bool
        {
            return $this->active;
        }

        public function all(): array
        {
            return array_keys($this->homes);
        }

        public function current(): ?string
        {
            return null;
        }

        public function ofPost(int $postId): ?string
        {
            return null;
        }

        public function translations(int $postId): array
        {
            return [];
        }

        public function homeUrl(string $code): ?string
        {
            return $this->homes[$code] ?? null;
        }
    };
}

function languageUrls(array $homes, array $fronts, bool $active = true): LanguageUrls
{
    $backend = Origin::fromUrl('https://cms.example.com');
    $front = Origin::fromUrl('https://www.example.com');

    return new LanguageUrls(new UrlMapper($backend, $front), $backend, $front, array_map(Origin::fromUrl(...), $fronts), fakeLanguages($homes, $active));
}

it('sends a language to its own front end', function (): void {
    $urls = languageUrls(['en' => 'https://cms.example.com/', 'fr' => 'https://cms.example.com/fr/'], ['fr' => 'https://www.example.fr']);

    expect($urls->toFront('https://cms.example.com/fr/blog/', 'fr'))->toBe('https://www.example.fr/fr/blog/')
        ->and($urls->toFront('https://cms.example.com/blog/', 'en'))->toBe('https://www.example.com/blog/');
});

it('uses the default front end for a language without one of its own, and for an unknown language', function (): void {
    $urls = languageUrls(['en' => 'https://cms.example.com/'], []);

    expect($urls->toFront('https://cms.example.com/blog/', 'en'))->toBe('https://www.example.com/blog/')
        ->and($urls->toFront('https://cms.example.com/blog/', 'de'))->toBe('https://www.example.com/blog/');
});

it('reads the WordPress host of a language when each language has its own (domain mode)', function (): void {
    $urls = languageUrls(['en' => 'https://cms.example.com/', 'fr' => 'https://cms.example.fr/'], ['fr' => 'https://www.example.fr']);

    expect($urls->toFront('https://cms.example.fr/blog/', 'fr'))->toBe('https://www.example.fr/blog/')
        ->and($urls->toFront('https://cms.example.fr/blog/'))->toBe('https://www.example.fr/blog/')
        ->and($urls->toFront('https://cms.example.com/blog/'))->toBe('https://www.example.com/blog/');
});

it('does not guess a language when the languages share the host', function (): void {
    $urls = languageUrls(['en' => 'https://cms.example.com/', 'fr' => 'https://cms.example.com/fr/'], ['fr' => 'https://www.example.fr']);

    expect($urls->toFront('https://cms.example.com/fr/blog/'))->toBe('https://www.example.com/fr/blog/');
});

it('falls back to the default mapper without a multilingual plugin', function (): void {
    expect(languageUrls([], ['fr' => 'https://www.example.fr'], false)->toFront('https://cms.example.com/blog/', 'fr'))->toBe('https://www.example.com/blog/');
});

it('ignores a language home that is not a URL', function (): void {
    $urls = languageUrls(['fr' => 'not a url'], ['fr' => 'https://www.example.fr']);

    expect($urls->toFront('https://cms.example.com/blog/', 'fr'))->toBe('https://www.example.fr/blog/')
        ->and($urls->toFront('not a url'))->toBe('not a url');
});
