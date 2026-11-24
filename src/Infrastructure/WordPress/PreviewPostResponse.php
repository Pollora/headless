<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

/**
 * Builds the REST answer for the preview of a post: the post as WordPress's own REST API shows it to its
 * editor, with the title, content and excerpt of the latest autosave (or of a revision) laid over it.
 *
 * Like WordPress's own preview, only what an autosave holds is laid over: the featured image, the terms and
 * the meta are those of the saved post.
 */
final class PreviewPostResponse
{
    /**
     * @param  string  $revision  "autosave" (the latest autosave of the user), "live" (the saved post) or a revision ID
     */
    public function build(int $postId, int $userId, string $revision): \WP_REST_Response|\WP_Error
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return new \WP_Error('preview_not_found', 'No such post to preview.', ['status' => 404]);
        }

        $source = $this->source($post, $userId, $revision);

        if ($source instanceof \WP_Error) {
            return $source;
        }

        $previous = get_current_user_id();
        wp_set_current_user($userId);

        try {
            $response = rest_do_request(new \WP_REST_Request('GET', rest_get_route_for_post($post)));
            $data = $response->get_data();

            if ($response->is_error() || ! is_array($data)) {
                return new \WP_Error('preview_not_found', 'No such post to preview.', ['status' => 404]);
            }

            if ($source instanceof \WP_Post) {
                $data = $this->overlay($data, $post, $source);
            }
        } finally {
            wp_set_current_user($previous);
        }

        $data['preview'] = [
            'source' => match (true) {
                ! $source instanceof \WP_Post => 'live',
                $revision === 'autosave' => 'autosave',
                default => 'revision',
            },
            'status' => $post->post_status,
            'modified_gmt' => $source instanceof \WP_Post ? str_replace(' ', 'T', $source->post_modified_gmt) : str_replace(' ', 'T', $post->post_modified_gmt),
            'scheduled_gmt' => $post->post_status === 'future' ? str_replace(' ', 'T', $post->post_date_gmt) : null,
        ];

        $answer = new \WP_REST_Response($data);
        $answer->header('Cache-Control', 'no-store, private');
        $answer->header('Vary', 'Authorization');
        $answer->header('X-Robots-Tag', 'noindex, nofollow');

        return $answer;
    }

    /**
     * The post whose fields are laid over the saved one: an autosave or a revision; null for the saved post itself.
     */
    private function source(\WP_Post $post, int $userId, string $revision): \WP_Post|\WP_Error|null
    {
        if ($revision === 'live') {
            return null;
        }

        if ($revision === 'autosave') {
            $autosave = wp_get_post_autosave($post->ID, $userId);

            // An autosave older than the saved post is stale (saving the post deletes it): the saved post wins. A tie goes to the autosave
            return $autosave instanceof \WP_Post && strtotime($autosave->post_modified_gmt) >= strtotime($post->post_modified_gmt) ? $autosave : null;
        }

        $revisionId = (int) $revision;
        $found = wp_get_post_revision($revisionId);

        if (! $found instanceof \WP_Post || $found->post_parent !== $post->ID) {
            return new \WP_Error('preview_not_found', 'No such revision for this post.', ['status' => 404]);
        }

        return $found;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function overlay(array $data, \WP_Post $post, \WP_Post $source): array
    {
        $copy = clone $post;
        $copy->post_title = $source->post_title;
        $copy->post_content = $source->post_content;
        $copy->post_excerpt = $source->post_excerpt;

        // Blocks and shortcodes read the global post
        $original = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $copy;
        setup_postdata($copy);

        try {
            $data['title']['rendered'] = apply_filters('the_title', $copy->post_title, $post->ID);
            $data['content']['rendered'] = apply_filters('the_content', $copy->post_content);
            $data['excerpt']['rendered'] = apply_filters('the_excerpt', get_the_excerpt($copy));
            $data['content']['protected'] = false;
        } finally {
            $GLOBALS['post'] = $original;

            if ($original instanceof \WP_Post) {
                setup_postdata($original);
            }
        }

        return $data;
    }
}
