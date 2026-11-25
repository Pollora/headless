<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Search;

/**
 * What a visitor searched for, kept within bounds: a term that is not too short or too long, a page size
 * the site accepts, and the content types it may look in.
 */
final readonly class SearchQuery
{
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 100;

    public const int DEFAULT_PER_PAGE = 10;

    /** Deep pages cost a scan of every result before them, and nobody reads page 5000 of a search */
    public const int MAX_PAGE = 100;

    /**
     * @param  list<string>  $types  the content types to look in: the ones asked for that the site exposes, or all of those it exposes
     */
    private function __construct(
        public string $term,
        public array $types,
        public ?string $language,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * @param  list<string>  $exposed  the types the site exposes, so that a request cannot reach another one
     * @param  list<string>  $languages  the language codes of the site, when it has languages
     */
    public static function fromRequest(string $term, string $types, ?string $language, int $page, int $perPage, int $maxPerPage, array $exposed, array $languages = []): ?self
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $term));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }

        $asked = array_values(array_filter(array_map(trim(...), explode(',', $types)), static fn (string $type): bool => $type !== ''));
        $kept = $asked === [] ? $exposed : array_values(array_intersect($asked, $exposed));
        $language = $language !== null && in_array($language, $languages, true) ? $language : null;

        return new self(
            mb_substr($term, 0, self::MAX_LENGTH),
            $kept,
            $language,
            min(max(1, $page), self::MAX_PAGE),
            min(max(1, $perPage > 0 ? $perPage : self::DEFAULT_PER_PAGE), max(1, $maxPerPage)),
        );
    }
}
