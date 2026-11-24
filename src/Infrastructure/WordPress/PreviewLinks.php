<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Application\Preview\PreviewService;

/**
 * Points the editor's Preview button to the front end, with a token only this user can use, once, for this post.
 *
 * The block editor takes its preview link from the REST autosave of the post, which builds it with
 * `get_preview_post_link()`: the `preview_post_link` filter below covers it and the classic editor alike.
 */
final readonly class PreviewLinks implements Module
{
    /**
     * @param  string  $previewUrl  where the front end handles previews, such as https://www.example.com/api/preview
     */
    public function __construct(
        private PreviewService $service,
        private string $previewUrl,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('preview_post_link', [$this, 'link'], 10, 2);
    }

    public function link(mixed $link, mixed $post = null): mixed
    {
        $userId = get_current_user_id();

        // Not an editor of this post: leave WordPress's own link, which will refuse them anyway
        if (! $post instanceof \WP_Post || $userId === 0 || ! user_can($userId, 'edit_post', $post->ID)) {
            return $link;
        }

        $path = parse_url((string) get_permalink($post), PHP_URL_PATH).(($query = parse_url((string) get_permalink($post), PHP_URL_QUERY)) ? '?'.$query : '');

        return add_query_arg([
            'token' => $this->service->linkToken($userId, $post->ID, get_current_blog_id()),
            'id' => $post->ID,
            'type' => $post->post_type,
            'path' => $path,
            'site' => get_current_blog_id(),
            'locale' => get_locale(),
        ], $this->previewUrl);
    }
}
