<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Seo;

use Closure;
use Pollora\Headless\Domain\Seo\Seo;
use Pollora\Headless\Domain\Seo\SeoProvider;

/**
 * A SEO plugin that adds its metadata as a JSON field to the REST answer of posts and terms (Yoast's
 * `yoast_head_json`, All in One SEO's `aioseo_head_json`): the field is read through the REST API, from inside.
 */
final readonly class JsonFieldProvider implements SeoProvider
{
    /**
     * @param  Closure(array<mixed>): Seo  $map
     */
    public function __construct(
        private string $name,
        private string $field,
        private Closure $map,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function forPost(int $postId): ?Seo
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post ? $this->read(rest_get_route_for_post($post)) : null;
    }

    public function forTerm(int $termId, string $taxonomy): ?Seo
    {
        return $this->read(rest_get_route_for_term($termId));
    }

    private function read(string $route): ?Seo
    {
        $json = $route === '' ? null : InternalRest::field($route, $this->field);

        return is_array($json) ? ($this->map)($json) : null;
    }
}
