<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Cors\CorsRequest;
use Pollora\Headless\Domain\Front\RequestTarget;
use Pollora\Headless\Domain\GraphQl\GraphQlPolicy;
use Pollora\Headless\Infrastructure\Config\Settings;
use Pollora\Headless\Infrastructure\Config\SettingsLoader;
use Pollora\Headless\Infrastructure\Exceptions\InvalidSettingsException;

/*
 * What a site gets when it says nothing, and where each limit lies.
 */

function chosenSettings(array $config = [], string $backend = 'https://cms.example.com'): Settings
{
    return Settings::fromArray($config + ['frontend_url' => 'https://www.example.com'], $backend);
}

it('closes and trims everything by default', function (): void {
    $settings = chosenSettings();

    expect([
        $settings->cleanHead, $settings->disableXmlRpc, $settings->disableFeeds, $settings->disableSitemaps, $settings->noindexOrigin,
        $settings->restrictUsers, $settings->disallowFileEdit, $settings->rewriteLinks, $settings->rewriteContentLinks, $settings->apiTokens,
        $settings->manifest, $settings->redirects, $settings->oldSlugRedirects, $settings->mediaRoutes, $settings->blocks, $settings->seo,
        $settings->search, $settings->forms,
    ])->toBe(array_fill(0, 18, true))
        ->and($settings->restPluginFilter)->toBeFalse()
        ->and($settings->previewLinkTtl)->toBe(300)
        ->and($settings->previewSessionTtl)->toBe(1800)
        ->and($settings->previewSecret)->toBeNull()
        ->and($settings->revalidationDebounce)->toBe(3)
        ->and($settings->revalidationAttempts)->toBe(5)
        ->and($settings->perPageMax)->toBeNull()
        ->and($settings->mediaCdn)->toBeNull();
});

it('keeps the query of a redirect by default', function (): void {
    expect(chosenSettings()->gate->decide(RequestTarget::fromUri('/a?b=c'))->location)->toBe('https://www.example.com/a?b=c');
});

it('switches off each part that is told to be off', function (string $key): void {
    $settings = chosenSettings([$key => ['enabled' => false]]);
    $property = ['search' => 'search', 'forms' => 'forms', 'seo' => 'seo', 'blocks' => 'blocks', 'media' => 'mediaRoutes', 'manifest' => 'manifest', 'redirects' => 'redirects', 'tokens' => 'apiTokens'][$key];

    expect($settings->{$property})->toBeFalse();
})->with(['search', 'forms', 'seo', 'blocks', 'media', 'manifest', 'redirects', 'tokens']);

it('opens none of the routes of a front end that is not there', function (): void {
    $settings = Settings::fromArray([], 'https://cms.example.com');

    expect([$settings->manifest, $settings->redirects, $settings->mediaRoutes, $settings->blocks, $settings->seo, $settings->search])->toBe(array_fill(0, 6, false))
        ->and($settings->previewUrl)->toBeNull();
});

it('takes the directory of WordPress from its address, without a trailing slash', function (): void {
    expect(chosenSettings(backend: 'https://cms.example.com/wp/')->basePath)->toBe('/wp');
});

it('accepts a preview secret from 32 characters', function (): void {
    expect(chosenSettings(['preview' => ['secret' => str_repeat('x', 32)]])->previewSecret)->toBe(str_repeat('x', 32));
});

it('refuses a preview secret of 31 characters', function (): void {
    chosenSettings(['preview' => ['secret' => str_repeat('x', 31)]]);
})->throws(InvalidSettingsException::class);

it('keeps the ceiling of per_page only where it lowers WordPress\'s', function (mixed $asked, ?int $kept): void {
    expect(chosenSettings(['per_page_max' => $asked])->perPageMax)->toBe($kept);
})->with([[0, null], [1, 1], [99, 99], [100, null], [101, null]]);

it('caches for a minute, five on a CDN, and allows a minute of stale answer by default', function (): void {
    expect(chosenSettings()->restCache?->cacheControl('GET', 200, false, false, '/wp/v2/posts'))->toBe('public, max-age=60, s-maxage=300, stale-while-revalidate=60');
});

it('sends no credentials and lets a preflight be kept ten minutes by default, one origin each once', function (): void {
    $cors = chosenSettings(['cors' => ['origins' => ['https://www.example.com', 'https://other.test']]])->cors;
    $decision = $cors?->decide(new CorsRequest('https://www.example.com', 'OPTIONS', 'GET'));

    expect($decision?->headers['Access-Control-Max-Age'])->toBe('600')
        ->and($decision?->headers)->not->toHaveKey('Access-Control-Allow-Credentials');
});

it('imposes the cautious GraphQL settings by default, and the numbers a project writes', function (): void {
    expect(chosenSettings()->graphql)->toEqual(new GraphQlPolicy)
        ->and(chosenSettings(['graphql' => ['batch_limit' => '7', 'max_depth' => -3, 'graphiql' => true]])->graphql)->toEqual(new GraphQlPolicy(batchLimit: 7, maxDepth: 0, graphiql: true))
        ->and(chosenSettings(['graphql' => ['batch_limit' => -1, 'max_depth' => '4']])->graphql)->toEqual(new GraphQlPolicy(batchLimit: 0, maxDepth: 4))
        ->and(chosenSettings(['graphql' => ['batch_limit' => 'many', 'max_depth' => null, 'introspection' => null]])->graphql)->toEqual(new GraphQlPolicy(introspection: null, maxDepth: null));
});

it('allows one batch query and one level of depth when asked to', function (): void {
    expect((new GraphQlPolicy(batchLimit: 1, maxDepth: 1))->forced())->toMatchArray(['batch_queries_enabled' => 'on', 'batch_limit' => 1, 'query_depth_enabled' => 'on', 'query_depth_max' => 1]);
});

it('ignores the blanks around the address of a language, and a blank one', function (): void {
    expect(array_keys(chosenSettings(['frontend_urls' => ['fr' => ' https://www.example.fr ', 'de' => '   ']])->languageFronts))->toBe(['fr']);
});

it('reads every target after one without an address, and names those without a name', function (): void {
    $targets = chosenSettings(['revalidation' => ['targets' => [
        ['url' => ''],
        ['url' => 'https://a.test', 'secret' => 'k', 'name' => ''],
        ['url' => 'https://b.test', 'secret' => 'k', 'name' => 7],
    ]]])->revalidationTargets;

    expect(array_map(static fn ($target): string => $target->name, $targets))->toBe(['target-1', '7']);
});

it('keeps the strings of a list that say something, and each watched option once', function (): void {
    $settings = chosenSettings(['revalidation' => ['options' => ['', ['nested'], 'blogname', 'mine', 5]]]);

    expect(array_slice($settings->revalidationOptions, -2))->toBe(['mine', '5'])
        ->and(array_is_list($settings->revalidationOptions))->toBeTrue()
        ->and($settings->revalidationOptions)->not->toContain('');
});

it('lays the settings of one site over the shared ones, and only those', function (): void {
    $config = ['a' => 1, 'b' => 2, 'sites' => [2 => ['b' => 3]]];

    expect(SettingsLoader::merge($config, null, 2))->toBe(['a' => 1, 'b' => 3])
        ->and(SettingsLoader::merge($config, null, 3))->toBe(['a' => 1, 'b' => 2])
        ->and(SettingsLoader::merge(['a' => 1, 'sites' => 'broken'], null, 2))->toBe(['a' => 1])
        ->and(SettingsLoader::merge(['a' => 1, 'sites' => [2 => 'broken']], null, 2))->toBe(['a' => 1]);
});
