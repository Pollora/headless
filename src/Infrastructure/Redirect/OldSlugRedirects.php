<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\Redirect;

use Pollora\Headless\Domain\Front\FrontUrls;
use Pollora\Headless\Domain\Front\UrlPath;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectSource;

/**
 * The old addresses of posts that were renamed: WordPress keeps each earlier slug in the `_wp_old_slug` meta and
 * redirects them on its own host. A front end has no such memory, so they are listed here, each one to the
 * current address of its post.
 *
 * The list is kept five minutes (and dropped when a post is saved): it reads the meta of every published post.
 */
final class OldSlugRedirects implements RedirectSource
{
    public const string TRANSIENT = 'headless_old_slugs';

    private const int LIMIT = 1000;

    public function __construct(private readonly FrontUrls $urls) {}

    public function redirects(): array
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached)) {
            return array_values(array_filter(array_map(static fn (mixed $row): ?Redirect => is_array($row) ? Redirect::tryFromArray($row) : null, $cached)));
        }

        $rules = $this->read();
        set_transient(self::TRANSIENT, array_map(static fn (Redirect $rule): array => $rule->toArray(), $rules), 300);

        return $rules;
    }

    public static function forget(): void
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * @return list<Redirect>
     */
    private function read(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.post_id, m.meta_value AS old_slug FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = %s AND p.post_type NOT IN ('revision', 'attachment', 'nav_menu_item') ORDER BY m.meta_id ASC LIMIT %d",
            '_wp_old_slug',
            'publish',
            self::LIMIT,
        ));

        $rules = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $post = get_post((int) $row->post_id);

            if (! $post instanceof \WP_Post || $row->old_slug === '' || $row->old_slug === $post->post_name) {
                continue;
            }

            $old = clone $post;
            $old->post_name = (string) $row->old_slug;

            try {
                $rules[] = new Redirect(
                    UrlPath::of($this->urls->toFront((string) get_permalink($old))),
                    UrlPath::of($this->urls->toFront((string) get_permalink($post))),
                    301,
                    false,
                    'old-slug',
                );
            } catch (\InvalidArgumentException) {
                // A post whose address cannot be a rule (plain permalinks give "/?p=12" for both): nothing to redirect
                continue;
            }
        }

        return $rules;
    }
}
