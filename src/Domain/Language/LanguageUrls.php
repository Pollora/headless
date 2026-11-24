<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Language;

use Pollora\Headless\Domain\Exceptions\InvalidOriginException;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\UrlMapper;

/**
 * Maps a WordPress URL to the front end of its language.
 *
 * A multilingual site may serve each language from its own front end (https://www.example.com and
 * https://www.example.fr). The WordPress side can also differ by language (a host per language in
 * Polylang's domain mode): the mapper of a language reads its own WordPress home. A language with
 * no front end of its own uses the default one; a language the plugin does not know uses the default mapper.
 */
final readonly class LanguageUrls implements FrontUrls
{
    /**
     * @param  array<string, Origin>  $fronts  front-end origin by language code
     */
    public function __construct(
        private UrlMapper $default,
        private Origin $defaultBackend,
        private Origin $defaultFront,
        private array $fronts,
        private Languages $languages,
    ) {}

    public function toFront(string $url, ?string $language = null): string
    {
        $language ??= $this->languageOfHost($url);

        if ($language === null || ! $this->languages->isActive()) {
            return $this->default->toFront($url);
        }

        return $this->mapperFor($language)->toFront($url);
    }

    private function mapperFor(string $language): UrlMapper
    {
        return new UrlMapper(
            $this->backendOf($language) ?? $this->defaultBackend,
            $this->fronts[$language] ?? $this->defaultFront,
        );
    }

    /** The language whose own host the URL is on; none when the languages share the host (directory mode). */
    private function languageOfHost(string $url): ?string
    {
        if (! $this->languages->isActive()) {
            return null;
        }

        try {
            $origin = Origin::fromUrl($url);
        } catch (InvalidOriginException) {
            return null;
        }

        $matches = [];

        foreach ($this->languages->all() as $code) {
            $backend = $this->backendOf($code);

            if ($backend instanceof Origin && $backend->sameHostAs($origin)) {
                $matches[] = $code;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function backendOf(string $language): ?Origin
    {
        $home = $this->languages->homeUrl($language);

        if ($home === null) {
            return null;
        }

        try {
            return Origin::fromUrl($home);
        } catch (InvalidOriginException) {
            return null;
        }
    }
}
