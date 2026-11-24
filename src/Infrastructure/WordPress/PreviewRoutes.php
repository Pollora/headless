<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Application\Preview\PreviewService;
use Pollora\Headless\Domain\Exceptions\PreviewForbiddenException;
use Pollora\Headless\Domain\Exceptions\PreviewNotFoundException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenExpiredException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenInvalidException;
use Pollora\Headless\Domain\Exceptions\PreviewTokenUsedException;
use Throwable;

/**
 * The preview API a front end calls, under `/wp-json/headless/v1/preview`:
 *
 * - `POST /exchange` with `{"token": "<link token>"}`: a session for the post (`hlp.…`), valid thirty minutes.
 * - `GET /posts/{id}?rev=autosave|live|<revision id>` with `Authorization: Bearer <session>`: the post as its editor sees it.
 *
 * Errors carry a stable code: preview_token_invalid, preview_token_expired (401), preview_token_used (409),
 * preview_forbidden (403), preview_not_found (404).
 */
final class PreviewRoutes implements Module
{
    public const string NAMESPACE = 'headless/v1';

    /** The user the request was authorised for, set by the permission check of the same request. */
    private int $userId = 0;

    public function __construct(
        private readonly PreviewService $service,
        private readonly PreviewPostResponse $posts,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/preview/exchange', [
            'methods' => 'POST',
            'callback' => [$this, 'exchange'],
            // Public on purpose: the front end has no WordPress login. The link token in the body is the credential.
            'permission_callback' => '__return_true',
            'args' => ['token' => ['type' => 'string', 'required' => true]],
        ]);

        register_rest_route(self::NAMESPACE, '/preview/posts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'read'],
            'permission_callback' => [$this, 'authorize'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'rev' => ['type' => 'string', 'default' => 'autosave', 'pattern' => '\A(autosave|live|\d+)\z'],
            ],
        ]);
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function exchange(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $token = $request->get_param('token');

        try {
            $session = $this->service->exchange(is_string($token) ? $token : '');
        } catch (Throwable $throwable) {
            return self::error($throwable);
        }

        $post = get_post($session->postId);

        $response = new \WP_REST_Response([
            'session' => $session->token,
            'expires_in' => $session->expiresIn,
            'post' => [
                'id' => $session->postId,
                'type' => $post instanceof \WP_Post ? $post->post_type : '',
                'status' => $post instanceof \WP_Post ? $post->post_status : '',
                'link' => $post instanceof \WP_Post ? get_permalink($post) : '',
            ],
        ]);
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Who is asking: a session for this post, or a user who is logged in (an API token, an application password)
     * and can edit it.
     *
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function authorize(\WP_REST_Request $request): bool|\WP_Error
    {
        $this->userId = 0;
        $postId = (int) $request->get_param('id');
        $header = $request->get_header('authorization');

        if (is_string($header) && preg_match('/\ABearer\s+(hlp\.\S+)\z/i', trim($header), $match) === 1) {
            try {
                $this->userId = $this->service->authorize($match[1], $postId)->userId;
            } catch (Throwable $exception) {
                return self::error($exception);
            }

            return true;
        }

        if (! is_user_logged_in()) {
            return new \WP_Error('preview_token_invalid', 'A preview session is required.', ['status' => 401]);
        }

        if (! current_user_can('edit_post', $postId)) {
            return new \WP_Error('preview_forbidden', 'You are not allowed to preview this post.', ['status' => 403]);
        }

        $this->userId = get_current_user_id();

        return true;
    }

    /**
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     */
    public function read(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        return $this->posts->build((int) $request->get_param('id'), $this->userId, (string) $request->get_param('rev'));
    }

    private static function error(Throwable $exception): \WP_Error
    {
        [$code, $status] = match (true) {
            $exception instanceof PreviewTokenExpiredException => ['preview_token_expired', 401],
            $exception instanceof PreviewTokenUsedException => ['preview_token_used', 409],
            $exception instanceof PreviewForbiddenException => ['preview_forbidden', 403],
            $exception instanceof PreviewNotFoundException => ['preview_not_found', 404],
            $exception instanceof PreviewTokenInvalidException => ['preview_token_invalid', 401],
            default => throw $exception,
        };

        return new \WP_Error($code, $exception->getMessage(), ['status' => $status]);
    }
}
