<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Media\MediaCatalog;
use Pollora\Headless\Domain\Scalar;

/**
 * Files of the media library in one shape (see NormalizedMedia):
 *
 * - `GET /wp-json/headless/v1/media/{id}`.
 * - the field `headless_featured_media` of a post, when `_fields` asks for it: the featured image in full, with no second request.
 */
final readonly class MediaRoutes implements Module
{
    public const string NAMESPACE = 'headless/v1';

    public const string FIELD = 'headless_featured_media';

    public function __construct(private MediaCatalog $media) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/media/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            // Public on purpose: a file a visitor can already see on the front end. A file attached to a post they may not read answers 404.
            'permission_callback' => '__return_true',
            'args' => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        foreach (get_post_types(['show_in_rest' => true, 'public' => true]) as $type) {
            if (post_type_supports($type, 'thumbnail')) {
                register_rest_field($type, self::FIELD, [
                    'get_callback' => [$this, 'featured'],
                    'schema' => ['description' => 'The featured image in full (ask for it in _fields).', 'type' => ['object', 'null'], 'context' => ['view', 'edit']],
                ]);
            }
        }
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $media = $this->media->for(Scalar::int($request->get_param('id')));

        return $media === null ? new \WP_Error('media_not_found', 'No such file.', ['status' => 404]) : rest_ensure_response($media);
    }

    /**
     * @param  array<string, mixed>  $post  the post as the REST controller prepared it
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     * @return array<string, mixed>|null
     */
    public function featured(array $post, string $field, \WP_REST_Request $request): ?array
    {
        if (! OptInField::requested($request, self::FIELD)) {
            return null;
        }

        $id = (int) get_post_thumbnail_id(Scalar::int($post['id'] ?? 0));

        return $id > 0 ? $this->media->for($id) : null;
    }
}
