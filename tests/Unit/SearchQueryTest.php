<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Search\SearchQuery;

function searchQuery(string $term = 'hello', string $types = '', ?string $lang = null, int $page = 1, int $perPage = 10, int $max = 50): ?SearchQuery
{
    return SearchQuery::fromRequest($term, $types, $lang, $page, $perPage, $max, ['post', 'page', 'product'], ['en', 'fr']);
}

it('tidies the term and refuses one that is too short', function (): void {
    expect(searchQuery("  hello \n  world  ")?->term)->toBe('hello world')
        ->and(searchQuery('a'))->toBeNull()
        ->and(searchQuery('   '))->toBeNull()
        ->and(searchQuery('é'))->toBeNull()
        ->and(mb_strlen(searchQuery(str_repeat('é', 300))?->term ?? ''))->toBe(SearchQuery::MAX_LENGTH);
});

it('looks in every exposed type unless some are named', function (): void {
    expect(searchQuery()?->types)->toBe(['post', 'page', 'product'])
        ->and(searchQuery(types: 'page, product')?->types)->toBe(['page', 'product'])
        ->and(searchQuery(types: 'page,secret,')?->types)->toBe(['page']);
});

it('searches nowhere when every type asked for is unknown', function (): void {
    expect(searchQuery(types: 'secret,hidden')?->types)->toBe([]);
});

it('keeps a language of the site only', function (): void {
    expect(searchQuery(lang: 'fr')?->language)->toBe('fr')
        ->and(searchQuery(lang: 'de')?->language)->toBeNull()
        ->and(searchQuery()?->language)->toBeNull();
});

it('bounds the page and its size', function (): void {
    expect(searchQuery(page: 99999)?->page)->toBe(SearchQuery::MAX_PAGE)
        ->and(searchQuery(page: -4)?->page)->toBe(1)
        ->and(searchQuery(page: 3)?->page)->toBe(3)
        ->and(searchQuery(perPage: 0)?->perPage)->toBe(SearchQuery::DEFAULT_PER_PAGE)
        ->and(searchQuery(perPage: 500)?->perPage)->toBe(50)
        ->and(searchQuery(perPage: 20, max: 5)?->perPage)->toBe(5)
        ->and(searchQuery(perPage: 20, max: 0)?->perPage)->toBe(1);
});
