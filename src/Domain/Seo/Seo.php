<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Seo;

use Closure;
use stdClass;

/**
 * The search and social metadata of a page, in one shape whichever SEO plugin produced it.
 *
 * The addresses it holds (canonical, `og:url`, images, the `@id` and `url` of the structured data, the links of the
 * breadcrumbs) are those of the plugin, which builds them from the WordPress host: `withUrls()` moves them.
 */
final readonly class Seo
{
    /**
     * @param  list<string>  $directives  robots directives other than index and follow, such as `max-image-preview:large`
     * @param  array{type: ?string, title: ?string, description: ?string, url: ?string, site_name: ?string, locale: ?string, images: list<array{url: string, width: ?int, height: ?int, alt: ?string}>}  $openGraph
     * @param  array{card: ?string, title: ?string, description: ?string, image: ?string, site: ?string, creator: ?string}  $twitter
     * @param  list<array<mixed>>  $jsonLd  the nodes of the structured data graph
     * @param  list<array{label: string, url: ?string}>  $breadcrumbs
     */
    public function __construct(
        public string $source,
        public ?string $title = null,
        public ?string $description = null,
        public bool $index = true,
        public bool $follow = true,
        public array $directives = [],
        public ?string $canonical = null,
        public array $openGraph = ['type' => null, 'title' => null, 'description' => null, 'url' => null, 'site_name' => null, 'locale' => null, 'images' => []],
        public array $twitter = ['card' => null, 'title' => null, 'description' => null, 'image' => null, 'site' => null, 'creator' => null],
        public array $jsonLd = [],
        public array $breadcrumbs = [],
    ) {}

    /**
     * @param  Closure(string): string  $map  moves an address of the WordPress host to the front end
     */
    public function withUrls(Closure $map): self
    {
        $url = static fn (?string $value): ?string => $value === null ? null : $map($value);
        $openGraph = $this->openGraph;
        $openGraph['url'] = $url($openGraph['url']);
        $openGraph['images'] = array_map(static fn (array $image): array => [...$image, 'url' => $map($image['url'])], $openGraph['images']);
        $twitter = $this->twitter;
        $twitter['image'] = $url($twitter['image']);

        return new self(
            $this->source,
            $this->title,
            $this->description,
            $this->index,
            $this->follow,
            $this->directives,
            $url($this->canonical),
            $openGraph,
            $twitter,
            array_map(static fn (array $node): array => self::walk($node, $map), $this->jsonLd),
            array_map(static fn (array $crumb): array => ['label' => $crumb['label'], 'url' => $url($crumb['url'])], $this->breadcrumbs),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'title' => $this->title,
            'description' => $this->description,
            'robots' => ['index' => $this->index, 'follow' => $this->follow, 'directives' => $this->directives],
            'canonical' => $this->canonical,
            'open_graph' => $this->openGraph,
            'twitter' => $this->twitter,
            'json_ld' => $this->jsonLd,
            'breadcrumbs' => $this->breadcrumbs,
        ];
    }

    /**
     * Applies the mapping to every string of the structured data that is an address.
     *
     * @param  array<mixed>  $value
     * @param  Closure(string): string  $map
     * @return array<mixed>
     */
    private static function walk(array $value, Closure $map): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::walk($item, $map);
            } elseif (is_string($item) && preg_match('#\Ahttps?://#i', $item) === 1) {
                $value[$key] = $map($item);
            } elseif ($item instanceof stdClass) {
                $value[$key] = $item;
            }
        }

        return $value;
    }
}
