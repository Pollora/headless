<?php

declare(strict_types=1);

namespace Pollora\Headless\Infrastructure\WordPress;

use Pollora\Headless\Domain\Block\BlockTree;

/**
 * The blocks of a post as stable JSON (see BlockTree), in the field `headless_blocks` of its REST answer, when
 * `_fields` names it: parsing and rendering every block is work a list of posts should not pay for by default.
 *
 * A post with a password gives no blocks (its content is not given either), and a block is rendered with the
 * post as the current one, as on its own page.
 */
final readonly class BlocksField implements Module
{
    public const string FIELD = 'headless_blocks';

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', [$this, 'fields']);
    }

    public function fields(): void
    {
        foreach (get_post_types(['show_in_rest' => true, 'public' => true]) as $type) {
            if (post_type_supports($type, 'editor')) {
                register_rest_field($type, self::FIELD, [
                    'get_callback' => [$this, 'blocks'],
                    'schema' => ['description' => 'The blocks of the content as stable JSON (ask for it in _fields).', 'type' => ['array', 'null'], 'context' => ['view', 'edit']],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $post  the post as the REST controller prepared it
     * @param  \WP_REST_Request<array<string, mixed>>  $request
     * @return list<array<string, mixed>>|null
     */
    public function blocks(array $post, string $field, \WP_REST_Request $request): ?array
    {
        $wpPost = OptInField::requested($request, self::FIELD) ? get_post((int) ($post['id'] ?? 0)) : null;

        if (! $wpPost instanceof \WP_Post || post_password_required($wpPost)) {
            return null;
        }

        return self::forContent($wpPost->post_content, $wpPost);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forContent(string $content, \WP_Post $post): array
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        // Blocks read the current post (the title of a post, a list of its terms)
        $original = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        try {
            return BlockTree::from(
                parse_blocks($content),
                static function (string $name, array $attributes) use ($registry): array {
                    $type = $registry->get_registered($name);

                    foreach ($type instanceof \WP_Block_Type ? $type->attributes ?? [] : [] as $key => $definition) {
                        if (is_array($definition) && array_key_exists('default', $definition) && ! array_key_exists($key, $attributes)) {
                            $attributes[$key] = $definition['default'];
                        }
                    }

                    return $attributes;
                },
                static fn (array $block): string => render_block($block),
                static fn (string $name): bool => ($type = $registry->get_registered($name)) instanceof \WP_Block_Type && $type->is_dynamic(),
            );
        } finally {
            $GLOBALS['post'] = $original;

            if ($original instanceof \WP_Post) {
                setup_postdata($original);
            }
        }
    }
}
