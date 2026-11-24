<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Seo;

/**
 * Reads what a SEO plugin gives (its JSON, its head) into the common shape, without ever failing on a value that is not there.
 */
final class SeoMapper
{
    /**
     * Yoast SEO: its `yoast_head_json` (keys `title`, `description`, `robots`, `canonical`, `og_*`, `twitter_*`, `schema`).
     *
     * @param  array<mixed>  $json
     */
    public static function yoast(array $json): Seo
    {
        $robots = is_array($json['robots'] ?? null) ? $json['robots'] : [];
        $directives = [];

        foreach ($robots as $key => $value) {
            if ($key !== 'index' && $key !== 'follow' && is_string($value) && $value !== '') {
                $directives[] = $value;
            }
        }

        $schema = is_array($json['schema'] ?? null) ? $json['schema'] : [];
        $graph = is_array($schema['@graph'] ?? null) ? array_values(array_filter($schema['@graph'], is_array(...))) : [];

        return new Seo(
            'yoast',
            // Yoast leaves `title` out when the page has the default one: the title of the social card is then the page's
            self::text($json['title'] ?? null) ?? self::text($json['og_title'] ?? null),
            self::text($json['description'] ?? null),
            ($robots['index'] ?? 'index') !== 'noindex',
            ($robots['follow'] ?? 'follow') !== 'nofollow',
            $directives,
            self::text($json['canonical'] ?? null),
            self::openGraph($json, 'og_', is_array($json['og_image'] ?? null) ? $json['og_image'] : []),
            self::twitter($json, 'twitter_', self::first($json['twitter_misc'] ?? null)),
            $graph,
            self::yoastBreadcrumbs($graph),
        );
    }

    /**
     * All in One SEO: its `aioseo_head_json`. Read from its documentation: every key is optional.
     *
     * @param  array<mixed>  $json
     */
    public static function aioseo(array $json): Seo
    {
        $robots = $json['robots'] ?? [];
        $robots = is_array($robots) ? implode(',', array_map(strval(...), array_filter($robots, is_scalar(...)))) : (string) $robots;

        $directives = array_values(array_filter(array_map(trim(...), explode(',', $robots)), static fn (string $d): bool => $d !== '' && ! in_array($d, ['index', 'follow', 'noindex', 'nofollow'], true)));
        $schema = is_array($json['schema'] ?? null) ? $json['schema'] : [];
        $graph = is_array($schema['@graph'] ?? null) ? array_values(array_filter($schema['@graph'], is_array(...))) : [];
        $image = self::text($json['og_image_url'] ?? null);

        return new Seo(
            'aioseo',
            self::text($json['title'] ?? null),
            self::text($json['description'] ?? null),
            ! str_contains($robots, 'noindex'),
            ! str_contains($robots, 'nofollow'),
            $directives,
            self::text($json['canonical_url'] ?? null),
            self::openGraph($json, 'og_', $image === null ? [] : [['url' => $image, 'width' => $json['og_image_width'] ?? null, 'height' => $json['og_image_height'] ?? null]]),
            self::twitter($json, 'twitter_', self::text($json['twitter_image_url'] ?? null)),
            $graph,
            self::yoastBreadcrumbs($graph),
        );
    }

    /**
     * SEOPress: the `data` of its `/seopress/v1/posts/{id}` route.
     *
     * @param  array<mixed>  $data
     */
    public static function seopress(array $data): Seo
    {
        $robots = is_array($data['robots'] ?? null) ? $data['robots'] : [];
        $og = is_array($data['og'] ?? null) ? $data['og'] : [];
        $tw = is_array($data['twitter'] ?? null) ? $data['twitter'] : [];
        $image = self::text($og['image'] ?? null);
        $crumbs = [];

        foreach (is_array($data['breadcrumbs'] ?? null) ? $data['breadcrumbs'] : [] as $crumb) {
            if (is_array($crumb) && is_string($crumb['name'] ?? $crumb['label'] ?? null)) {
                $crumbs[] = ['label' => (string) ($crumb['name'] ?? $crumb['label']), 'url' => self::text($crumb['url'] ?? null)];
            }
        }

        return new Seo(
            'seopress',
            self::text($data['title'] ?? null),
            self::text($data['description'] ?? null),
            ! self::truthy($robots['noindex'] ?? null),
            ! self::truthy($robots['nofollow'] ?? null),
            [],
            self::text($data['canonical'] ?? null),
            ['type' => self::text($og['type'] ?? null), 'title' => self::text($og['title'] ?? null), 'description' => self::text($og['description'] ?? null), 'url' => self::text($og['url'] ?? null), 'site_name' => self::text($og['site_name'] ?? null), 'locale' => null, 'images' => $image === null ? [] : [['url' => $image, 'width' => null, 'height' => null, 'alt' => null]]],
            ['card' => self::text($tw['card'] ?? null), 'title' => self::text($tw['title'] ?? null), 'description' => self::text($tw['description'] ?? null), 'image' => self::text($tw['image'] ?? null), 'site' => null, 'creator' => null],
            [],
            $crumbs,
        );
    }

    /**
     * Rank Math: the head it prints (its `/rankmath/v1/getHead` route gives HTML only).
     */
    public static function headHtml(string $html, string $source = 'rankmath'): Seo
    {
        $meta = [];

        if (preg_match_all('#<meta\s+([^>]*?)/?>#is', $html, $tags) > 0) {
            foreach ($tags[1] as $attributes) {
                $name = self::attribute($attributes, 'name') ?? self::attribute($attributes, 'property');
                $content = self::attribute($attributes, 'content');

                if ($name !== null && $content !== null) {
                    $meta[$name][] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        $canonical = preg_match('#<link\s+[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $c) === 1 ? html_entity_decode($c[1]) : null;
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t) === 1 ? trim(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5)) : null;
        $robots = strtolower($meta['robots'][0] ?? '');
        $directives = array_values(array_filter(array_map(trim(...), explode(',', $robots)), static fn (string $d): bool => $d !== '' && ! in_array($d, ['index', 'follow', 'noindex', 'nofollow'], true)));
        $graph = [];

        if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts) > 0) {
            foreach ($scripts[1] as $script) {
                $decoded = json_decode(trim($script), true);
                $nodes = is_array($decoded['@graph'] ?? null) ? $decoded['@graph'] : (is_array($decoded) ? [$decoded] : []);
                array_push($graph, ...array_values(array_filter($nodes, is_array(...))));
            }
        }

        $images = [];

        foreach ($meta['og:image'] ?? [] as $i => $url) {
            $images[] = ['url' => $url, 'width' => isset($meta['og:image:width'][$i]) ? (int) $meta['og:image:width'][$i] : null, 'height' => isset($meta['og:image:height'][$i]) ? (int) $meta['og:image:height'][$i] : null, 'alt' => $meta['og:image:alt'][$i] ?? null];
        }

        return new Seo(
            $source,
            $title !== '' ? $title : null,
            $meta['description'][0] ?? null,
            ! str_contains($robots, 'noindex'),
            ! str_contains($robots, 'nofollow'),
            $directives,
            $canonical,
            ['type' => $meta['og:type'][0] ?? null, 'title' => $meta['og:title'][0] ?? null, 'description' => $meta['og:description'][0] ?? null, 'url' => $meta['og:url'][0] ?? null, 'site_name' => $meta['og:site_name'][0] ?? null, 'locale' => $meta['og:locale'][0] ?? null, 'images' => $images],
            ['card' => $meta['twitter:card'][0] ?? null, 'title' => $meta['twitter:title'][0] ?? null, 'description' => $meta['twitter:description'][0] ?? null, 'image' => $meta['twitter:image'][0] ?? null, 'site' => $meta['twitter:site'][0] ?? null, 'creator' => $meta['twitter:creator'][0] ?? null],
            $graph,
            self::yoastBreadcrumbs($graph),
        );
    }

    /**
     * @param  array<mixed>  $json
     * @param  array<mixed>  $images
     * @return array{type: ?string, title: ?string, description: ?string, url: ?string, site_name: ?string, locale: ?string, images: list<array{url: string, width: ?int, height: ?int, alt: ?string}>}
     */
    private static function openGraph(array $json, string $prefix, array $images): array
    {
        $list = [];

        foreach ($images as $image) {
            if (is_array($image) && is_string($image['url'] ?? null) && $image['url'] !== '') {
                $list[] = ['url' => $image['url'], 'width' => is_numeric($image['width'] ?? null) ? (int) $image['width'] : null, 'height' => is_numeric($image['height'] ?? null) ? (int) $image['height'] : null, 'alt' => self::text($image['alt'] ?? null)];
            }
        }

        return [
            'type' => self::text($json[$prefix.'type'] ?? null),
            'title' => self::text($json[$prefix.'title'] ?? null),
            'description' => self::text($json[$prefix.'description'] ?? null),
            'url' => self::text($json[$prefix.'url'] ?? null),
            'site_name' => self::text($json[$prefix.'site_name'] ?? null),
            'locale' => self::text($json[$prefix.'locale'] ?? null),
            'images' => $list,
        ];
    }

    /**
     * @param  array<mixed>  $json
     * @return array{card: ?string, title: ?string, description: ?string, image: ?string, site: ?string, creator: ?string}
     */
    private static function twitter(array $json, string $prefix, mixed $image): array
    {
        $image ??= $json[$prefix.'image'] ?? null;

        return [
            'card' => self::text($json[$prefix.'card'] ?? null),
            'title' => self::text($json[$prefix.'title'] ?? null),
            'description' => self::text($json[$prefix.'description'] ?? null),
            'image' => self::text(is_array($image) ? ($image['url'] ?? null) : $image),
            'site' => self::text($json[$prefix.'site'] ?? null),
            'creator' => self::text($json[$prefix.'creator'] ?? null),
        ];
    }

    /**
     * @param  list<array<mixed>>  $graph
     * @return list<array{label: string, url: ?string}>
     */
    private static function yoastBreadcrumbs(array $graph): array
    {
        foreach ($graph as $node) {
            $types = (array) ($node['@type'] ?? []);

            if (in_array('BreadcrumbList', $types, true) && is_array($node['itemListElement'] ?? null)) {
                $crumbs = [];

                foreach ($node['itemListElement'] as $item) {
                    $thing = is_array($item['item'] ?? null) ? $item['item'] : [];
                    $label = $item['name'] ?? $thing['name'] ?? null;

                    if (is_string($label)) {
                        $crumbs[] = ['label' => $label, 'url' => self::text(is_string($item['item'] ?? null) ? $item['item'] : ($thing['@id'] ?? null))];
                    }
                }

                return $crumbs;
            }
        }

        return [];
    }

    private static function attribute(string $attributes, string $name): ?string
    {
        return preg_match('#(?:^|\s)'.preg_quote($name, '#').'=(["\'])(.*?)\1#is', $attributes, $m) === 1 ? $m[2] : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'true'], true);
    }

    private static function first(mixed $value): mixed
    {
        return is_array($value) ? ($value[0] ?? null) : null;
    }
}
