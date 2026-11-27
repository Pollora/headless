<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Exceptions\InvalidOriginException;
use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Manifest\MenuTree;
use Pollora\Headless\Domain\Scalar;

/**
 * Everything a front end needs to know about the site before it renders its first page, in one answer:
 * the site and its languages, the menus (as trees, with front-end paths), the front page, the content types
 * and taxonomies with their REST routes.
 *
 * Nothing in it is secret: it is what a visitor sees in the menus and the address bar. It is public and
 * cacheable, and the same on every call until the site changes (no clock inside, so an ETag can hold).
 */
final readonly class SiteManifest
{
    public function __construct(
        private FrontUrls $urls,
        private Origin $front,
        private Languages $languages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'version' => 1,
            'site' => $this->site(),
            'languages' => $this->languageList(),
            'front_page' => [
                'show_on_front' => Scalar::string(get_option('show_on_front', 'posts')),
                'page_on_front' => Scalar::int(get_option('page_on_front', 0)),
                'page_for_posts' => Scalar::int(get_option('page_for_posts', 0)),
                'posts_per_page' => Scalar::int(get_option('posts_per_page', 10)),
            ],
            'menus' => $this->menus(),
            'locations' => $this->locations(),
            'post_types' => $this->postTypes(),
            'taxonomies' => $this->taxonomies(),
            'permalink_structure' => Scalar::string(get_option('permalink_structure', '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function site(): array
    {
        return [
            'id' => get_current_blog_id(),
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => $this->front->toString(),
            'wordpress_url' => home_url(),
            'locale' => get_locale(),
            'language' => $this->languages->current() ?? substr(get_locale(), 0, 2),
            'timezone' => wp_timezone_string(),
            'date_format' => Scalar::string(get_option('date_format')),
            'time_format' => Scalar::string(get_option('time_format')),
            'multisite' => is_multisite(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function languageList(): array
    {
        if (! $this->languages->isActive()) {
            return [];
        }

        $list = [];

        foreach ($this->languages->all() as $code) {
            $home = $this->languages->homeUrl($code);
            $list[] = ['code' => $code, 'wordpress_url' => $home, 'url' => $home === null ? $this->front->toString() : $this->urls->toFront($home, $code)];
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menus(): array
    {
        $menus = [];

        foreach (wp_get_nav_menus() as $menu) {
            $items = [];

            foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $item) {
                if (! is_object($item)) {
                    continue;
                }

                $url = $this->urls->toFront(Scalar::string($item->url ?? ''));

                $items[] = [
                    'id' => Scalar::int($item->ID ?? 0),
                    'parent' => Scalar::int($item->menu_item_parent ?? 0),
                    'order' => Scalar::int($item->menu_order ?? 0),
                    'title' => Scalar::string($item->title ?? ''),
                    'url' => $url,
                    'path' => $this->pathOnFront($url),
                    'target' => Scalar::string($item->target ?? ''),
                    'classes' => array_values(array_filter((array) ($item->classes ?? []), static fn (mixed $class): bool => is_string($class) && $class !== '')),
                    'type' => Scalar::string($item->type ?? ''),
                    'object' => Scalar::string($item->object ?? ''),
                    'object_id' => Scalar::int($item->object_id ?? 0),
                ];
            }

            $menus[] = ['id' => $menu->term_id, 'slug' => $menu->slug, 'name' => $menu->name, 'items' => MenuTree::build($items)];
        }

        usort($menus, static fn (array $a, array $b): int => $a['slug'] <=> $b['slug']);

        return $menus;
    }

    /**
     * @return list<array{location: string, description: string, menu: ?string}>
     */
    private function locations(): array
    {
        $assigned = get_nav_menu_locations();
        $locations = [];

        foreach (get_registered_nav_menus() as $location => $description) {
            $menu = isset($assigned[$location]) ? wp_get_nav_menu_object($assigned[$location]) : false;
            $locations[] = ['location' => (string) $location, 'description' => Scalar::string($description), 'menu' => $menu instanceof \WP_Term ? $menu->slug : null];
        }

        usort($locations, static fn (array $a, array $b): int => $a['location'] <=> $b['location']);

        return $locations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function postTypes(): array
    {
        $types = [];

        foreach (get_post_types(['show_in_rest' => true, 'public' => true], 'objects') as $type) {
            $types[] = [
                'name' => $type->name,
                'label' => $type->label,
                'rest_base' => $type->rest_base ?: $type->name,
                'rest_namespace' => $type->rest_namespace ?: 'wp/v2',
                'hierarchical' => (bool) $type->hierarchical,
                'has_archive' => $type->has_archive,
                'rewrite_slug' => is_array($type->rewrite) ? Scalar::string($type->rewrite['slug'] ?? null, $type->name) : $type->name,
            ];
        }

        usort($types, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $types;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taxonomies(): array
    {
        $taxonomies = [];

        foreach (get_taxonomies(['show_in_rest' => true, 'public' => true], 'objects') as $taxonomy) {
            $taxonomies[] = [
                'name' => $taxonomy->name,
                'label' => $taxonomy->label,
                'rest_base' => $taxonomy->rest_base ?: $taxonomy->name,
                'rest_namespace' => $taxonomy->rest_namespace ?: 'wp/v2',
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'post_types' => array_values($taxonomy->object_type),
                'rewrite_slug' => is_array($taxonomy->rewrite) ? Scalar::string($taxonomy->rewrite['slug'] ?? null, $taxonomy->name) : $taxonomy->name,
            ];
        }

        usort($taxonomies, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $taxonomies;
    }

    /** The path (and query) of an address on a front end of the site, null for another site. */
    private function pathOnFront(string $url): ?string
    {
        try {
            $origin = Origin::fromUrl($url);
        } catch (InvalidOriginException) {
            return null;
        }

        $known = [$this->front];

        foreach ($this->languages->isActive() ? $this->languages->all() : [] as $code) {
            $home = $this->languages->homeUrl($code);

            if ($home !== null) {
                $known[] = Origin::fromUrl($this->urls->toFront($home, $code));
            }
        }

        foreach ($known as $front) {
            if ($front->sameHostAs($origin)) {
                $query = parse_url($url, PHP_URL_QUERY);

                return ((string) parse_url($url, PHP_URL_PATH) ?: '/').(is_string($query) ? '?'.$query : '');
            }
        }

        return null;
    }
}
