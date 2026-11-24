<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * Something changed on the WordPress side that a front end may show: a post, a term, a menu, an option, a file.
 *
 * Tags name what changed (`post:12`, `type:post`, `term:5`, `menu:primary`, `options`, `media`); URLs are the
 * pages concerned, on the front end. A front end revalidates by tag or by URL, whichever its framework offers.
 */
final readonly class ChangeEvent
{
    public const string POST_PUBLISHED = 'post.published';

    public const string POST_UPDATED = 'post.updated';

    public const string POST_UNPUBLISHED = 'post.unpublished';

    public const string POST_DELETED = 'post.deleted';

    public const string TERM_CHANGED = 'term.changed';

    public const string MENU_CHANGED = 'menu.changed';

    public const string OPTIONS_CHANGED = 'options.changed';

    public const string MEDIA_CHANGED = 'media.changed';

    public const string PING = 'ping';

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $urls  absolute URLs on the front end
     * @param  array{id: int, type: string, slug: string, old_slug: ?string, status_from: ?string, status_to: ?string}|null  $post
     */
    public function __construct(
        public string $name,
        public array $tags,
        public array $urls = [],
        public ?array $post = null,
        public int $siteId = 1,
        public string $locale = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['event' => $this->name, 'tags' => $this->tags, 'urls' => $this->urls, 'post' => $this->post, 'site' => $this->siteId, 'locale' => $this->locale];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['event'] ?? null)) {
            return null;
        }

        $post = $data['post'] ?? null;

        if (is_array($post)) {
            $post = [
                'id' => is_int($post['id'] ?? null) ? $post['id'] : 0,
                'type' => is_string($post['type'] ?? null) ? $post['type'] : '',
                'slug' => is_string($post['slug'] ?? null) ? $post['slug'] : '',
                'old_slug' => is_string($post['old_slug'] ?? null) ? $post['old_slug'] : null,
                'status_from' => is_string($post['status_from'] ?? null) ? $post['status_from'] : null,
                'status_to' => is_string($post['status_to'] ?? null) ? $post['status_to'] : null,
            ];
        } else {
            $post = null;
        }

        return new self(
            $data['event'],
            self::strings($data['tags'] ?? []),
            self::strings($data['urls'] ?? []),
            $post,
            is_int($data['site'] ?? null) ? $data['site'] : 1,
            is_string($data['locale'] ?? null) ? $data['locale'] : '',
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], is_string(...)));
    }
}
