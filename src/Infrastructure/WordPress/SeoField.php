<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * The search and social metadata of a post or a term, in one shape whichever SEO plugin the site runs (see Seo),
 * as the field `headless_seo` of its REST answer when `_fields` names it. Every address in it is on the front end.
 */
final readonly class SeoField implements Module
{
    public const string FIELD = 'headless_seo';

    public function __construct(
        private SeoProvider $provider,
        private FrontUrls $urls,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'fields']);
    }

    public function fields(): void
    {
        $schema = ['description' => 'Search and social metadata in one shape (ask for it in _fields).', 'type' => ['object', 'null'], 'context' => ['view', 'edit']];

        foreach (get_post_types(['show_in_rest' => true, 'public' => true]) as $type) {
            register_rest_field($type, self::FIELD, ['get_callback' => [$this, 'forPost'], 'schema' => $schema]);
        }

        foreach (get_taxonomies(['show_in_rest' => true, 'public' => true]) as $taxonomy) {
            register_rest_field($taxonomy, self::FIELD, ['get_callback' => [$this, 'forTerm'], 'schema' => $schema]);
        }
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     * @return array<string, mixed>|null
     */
    public function forPost(array $post, string $field, \WP_REST_Request $request): ?array
    {
        if (! OptInField::requested($request, self::FIELD)) {
            return null;
        }

        return $this->provider->forPost((int) ($post['id'] ?? 0))?->withUrls(fn (string $url): string => $this->urls->toFront($url))->toArray();
    }

    /**
     * @param  array<string, mixed>  $term
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     * @return array<string, mixed>|null
     */
    public function forTerm(array $term, string $field, \WP_REST_Request $request): ?array
    {
        if (! OptInField::requested($request, self::FIELD)) {
            return null;
        }

        return $this->provider->forTerm((int) ($term['id'] ?? 0), (string) ($term['taxonomy'] ?? ''))?->withUrls(fn (string $url): string => $this->urls->toFront($url))->toArray();
    }
}
