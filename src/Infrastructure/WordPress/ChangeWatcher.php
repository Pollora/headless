<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Application\Revalidation\Revalidator;
use Pollora\Headless\Domain\Language\Languages;
use Pollora\Headless\Domain\Revalidation\ChangeEvent;

/**
 * Watches WordPress for what a front end shows, and tells the Revalidator.
 *
 * - Posts: published, updated, unpublished (draft, trash, private), deleted. A save fires several hooks and
 *   the old slug is known only after the status transition, so the changes are gathered in memory and recorded
 *   once, at `shutdown`: one event per post per request.
 * - Terms, menus, a short list of site options, files of the media library.
 *
 * Only changes a visitor could see are reported: saving a draft tells nobody.
 */
final class ChangeWatcher implements Module
{
    /** @var array<int, array{post: \WP_Post, from: string, to: string, before: ?\WP_Post}> */
    private array $posts = [];

    /** @var list<ChangeEvent> */
    private array $others = [];

    /**
     * @param  list<string>  $options  names of the options whose change is reported
     */
    public function __construct(
        private readonly Revalidator $revalidator,
        private readonly Languages $languages,
        private readonly array $options,
    ) {}

    /** @return list<string> */
    public static function defaultOptions(): array
    {
        return ['blogname', 'blogdescription', 'home', 'siteurl', 'page_on_front', 'page_for_posts', 'show_on_front', 'permalink_structure', 'category_base', 'tag_base', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'gmt_offset', 'WPLANG', 'site_icon', 'sidebars_widgets', 'headless_redirects'];
    }

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('transition_post_status', [$this, 'postTransition'], 10, 3);
        $hooks->addAction('post_updated', [$this, 'postUpdated'], 10, 3);
        $hooks->addAction('before_delete_post', [$this, 'postDeleting'], 10, 2);

        foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
            $hooks->addAction($hook, [$this, 'termChanged'], 10, 3);
        }

        foreach (['wp_create_nav_menu', 'wp_update_nav_menu', 'wp_delete_nav_menu'] as $hook) {
            $hooks->addAction($hook, [$this, 'menuChanged']);
        }

        $hooks->addAction('wp_update_nav_menu_item', [$this, 'menuChanged']);

        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            $hooks->addAction($hook, [$this, 'optionChanged']);
        }

        foreach (['add_attachment', 'edit_attachment', 'delete_attachment'] as $hook) {
            $hooks->addAction($hook, [$this, 'mediaChanged']);
        }

        $hooks->addAction('shutdown', [$this, 'commit'], 20);
    }

    public function postTransition(mixed $new, mixed $old, mixed $post): void
    {
        if (! $post instanceof \WP_Post || ! is_string($new) || ! is_string($old) || ! $this->isWatched($post->post_type)) {
            return;
        }

        if ($new !== 'publish' && $old !== 'publish') {
            return;
        }

        // Several transitions in one request: from the first state to the last
        $this->posts[$post->ID] = ['post' => $post, 'from' => $this->posts[$post->ID]['from'] ?? $old, 'to' => $new, 'before' => $this->posts[$post->ID]['before'] ?? null];
    }

    public function postUpdated(mixed $id, mixed $after, mixed $before): void
    {
        if (is_int($id) && isset($this->posts[$id]) && $before instanceof \WP_Post) {
            $this->posts[$id]['before'] = $before;
        }
    }

    public function postDeleting(mixed $id, mixed $post): void
    {
        if (! is_int($id) || ! $post instanceof \WP_Post || $post->post_status !== 'publish' || ! $this->isWatched($post->post_type)) {
            return;
        }

        $this->posts[$id] = ['post' => $post, 'from' => 'publish', 'to' => 'deleted', 'before' => null];
    }

    public function termChanged(mixed $termId, mixed $ttId = null, mixed $taxonomy = null): void
    {
        if (! is_int($termId) || ! is_string($taxonomy)) {
            return;
        }

        $object = get_taxonomy($taxonomy);

        if (! $object instanceof \WP_Taxonomy || ! $object->show_in_rest) {
            return;
        }

        $link = get_term_link($termId, $taxonomy);

        $this->others[] = new ChangeEvent(
            ChangeEvent::TERM_CHANGED,
            ['term:'.$termId, 'taxonomy:'.$taxonomy, 'site:'.get_current_blog_id()],
            is_string($link) ? [$link] : [],
            null,
            get_current_blog_id(),
            $this->locale(null),
        );
    }

    public function menuChanged(mixed $menuId): void
    {
        if (! is_int($menuId)) {
            return;
        }

        $menu = wp_get_nav_menu_object($menuId);
        $tags = ['menus', 'menu:'.$menuId, 'site:'.get_current_blog_id()];

        if ($menu instanceof \WP_Term) {
            $tags[] = 'menu:'.$menu->slug;
        }

        $this->others[] = new ChangeEvent(ChangeEvent::MENU_CHANGED, $tags, [], null, get_current_blog_id(), $this->locale(null));
    }

    public function optionChanged(mixed $option): void
    {
        if (is_string($option) && in_array($option, $this->options, true)) {
            $this->others[] = new ChangeEvent(ChangeEvent::OPTIONS_CHANGED, ['options', 'option:'.$option, 'site:'.get_current_blog_id()], [], null, get_current_blog_id(), $this->locale(null));
        }
    }

    public function mediaChanged(mixed $id): void
    {
        if (is_int($id)) {
            $this->others[] = new ChangeEvent(ChangeEvent::MEDIA_CHANGED, ['media', 'media:'.$id, 'site:'.get_current_blog_id()], [], null, get_current_blog_id(), $this->locale(null));
        }
    }

    /** Hands what was gathered to the Revalidator: once per request, at shutdown. */
    public function commit(): void
    {
        $events = $this->others;

        foreach ($this->posts as $change) {
            $event = $this->postEvent($change['post'], $change['from'], $change['to'], $change['before']);

            if ($event instanceof ChangeEvent) {
                $events[] = $event;
            }
        }

        $this->posts = [];
        $this->others = [];

        foreach ($events as $event) {
            $this->revalidator->record($event);
        }
    }

    private function postEvent(\WP_Post $post, string $from, string $to, ?\WP_Post $before): ?ChangeEvent
    {
        $name = match (true) {
            $to === 'deleted' => ChangeEvent::POST_DELETED,
            $from !== 'publish' && $to === 'publish' => ChangeEvent::POST_PUBLISHED,
            $from === 'publish' && $to === 'publish' => ChangeEvent::POST_UPDATED,
            $from === 'publish' => ChangeEvent::POST_UNPUBLISHED,
            default => null,
        };

        if ($name === null) {
            return null;
        }

        $slug = (string) preg_replace('/__trashed\z/', '', $post->post_name);
        $oldSlug = $before instanceof \WP_Post && $before->post_name !== $post->post_name ? $before->post_name : null;

        $tags = ['post:'.$post->ID, 'type:'.$post->post_type, 'site:'.get_current_blog_id()];

        foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
            if (! $taxonomy->show_in_rest) {
                continue;
            }

            $terms = get_the_terms($post, $taxonomy->name);

            foreach (is_array($terms) ? $terms : [] as $term) {
                $tags[] = 'term:'.$term->term_id;
            }
        }

        $urls = [];

        if ($to === 'publish') {
            $urls[] = (string) get_permalink($post);
        }

        // The address the post had while it was published: it is the one the front end must drop
        if ($from === 'publish') {
            $published = clone $post;
            $published->post_status = 'publish';
            $published->post_name = $oldSlug ?? $slug;
            $urls[] = (string) get_permalink($published);
        }

        return new ChangeEvent(
            $name,
            $tags,
            array_values(array_unique(array_filter($urls))),
            ['id' => $post->ID, 'type' => $post->post_type, 'slug' => $slug, 'old_slug' => $oldSlug, 'status_from' => $from, 'status_to' => $to],
            get_current_blog_id(),
            $this->locale($post->ID),
        );
    }

    private function isWatched(string $postType): bool
    {
        if (in_array($postType, ['revision', 'nav_menu_item', 'auto-draft', 'customize_changeset', 'attachment'], true)) {
            return false;
        }

        $type = get_post_type_object($postType);

        return $type instanceof \WP_Post_Type && $type->show_in_rest;
    }

    /** The language of the post when a multilingual plugin tells it, the site's locale otherwise. */
    private function locale(?int $postId): string
    {
        if ($postId !== null && $this->languages->isActive()) {
            $language = $this->languages->ofPost($postId);

            if ($language !== null) {
                return $language;
            }
        }

        return get_locale();
    }
}
