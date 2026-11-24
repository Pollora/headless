<?php

declare(strict_types=1);

use Pollora\Headless\Infrastructure\WordPress\BlocksField;
use Pollora\Headless\Infrastructure\WordPress\WordPressHooks;

/**
 * Blocks as stable JSON against a real WordPress: the real block parser, the real block types, the real renderer.
 */
function blocksFor(string $content, string $status = 'publish', string $password = ''): array
{
    (new BlocksField)->register(new WordPressHooks);
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();

    $id = wp_insert_post(['post_title' => 'Blocks', 'post_content' => $content, 'post_status' => $status, 'post_password' => $password]);
    $request = new WP_REST_Request('GET', "/wp/v2/posts/$id");
    $request->set_param('_fields', 'id,headless_blocks');
    $data = rest_do_request($request)->get_data();
    wp_delete_post($id, true);

    return $data;
}

afterEach(function (): void {
    foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
        foreach ($GLOBALS['wp_filter'][$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];

                if (is_array($function) && is_object($function[0]) && str_starts_with($function[0]::class, 'Pollora\\Headless\\')) {
                    remove_filter($hook, $function, $priority);
                }
            }
        }
    }
});

it('gives the blocks of a post in a stable shape, nested', function (): void {
    $data = blocksFor("<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Title</h3>\n<!-- /wp:heading -->\n\n<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inside</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->");
    $blocks = $data['headless_blocks'];

    expect($blocks)->toHaveCount(2)
        ->and(array_keys($blocks[0]))->toBe(['id', 'name', 'attributes', 'html', 'rendered', 'inner_blocks'])
        ->and($blocks[0])->toMatchArray(['id' => '0', 'name' => 'core/heading'])
        ->and($blocks[0]['attributes'])->toMatchArray(['level' => 3])
        ->and($blocks[0]['html'])->toContain('<h3 class="wp-block-heading">Title</h3>')
        ->and($blocks[0]['rendered'])->toContain('Title')
        ->and($blocks[1]['name'])->toBe('core/group')
        ->and($blocks[1]['rendered'])->toContain('Inside')
        ->and($blocks[1]['inner_blocks'])->toHaveCount(1)
        ->and($blocks[1]['inner_blocks'][0])->toMatchArray(['id' => '1.0', 'name' => 'core/paragraph'])
        ->and($blocks[1]['inner_blocks'][0])->not->toHaveKey('rendered');
});

it('renders a dynamic block that has no HTML of its own, even inside another block', function (): void {
    $data = blocksFor("<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:latest-posts {\"postsToShow\":2} /--></div>\n<!-- /wp:group -->");
    $inner = $data['headless_blocks'][0]['inner_blocks'][0];

    expect($inner['name'])->toBe('core/latest-posts')
        ->and($inner['html'])->toBe('')
        ->and($inner['attributes'])->toMatchArray(['postsToShow' => 2])
        ->and($inner['rendered'])->toContain('wp-block-latest-posts');
});

it('completes the attributes with the defaults of the block type', function (): void {
    $data = blocksFor('<!-- wp:latest-posts /-->');
    $attributes = $data['headless_blocks'][0]['attributes'];

    expect($attributes)->toHaveKey('postsToShow')->and($attributes['postsToShow'])->toBe(5);
});

it('turns classic content into one freeform block', function (): void {
    $data = blocksFor('<p>Written in the classic editor.</p>');

    expect($data['headless_blocks'])->toHaveCount(1)
        ->and($data['headless_blocks'][0]['name'])->toBe('core/freeform')
        ->and($data['headless_blocks'][0]['html'])->toContain('classic editor');
});

it('gives nothing for a post with a password, and no blocks for an empty post', function (): void {
    $protected = blocksFor('<!-- wp:paragraph --><p>Secret</p><!-- /wp:paragraph -->', 'publish', 'hunter2');
    $empty = blocksFor('');

    expect($protected['headless_blocks'])->toBeNull()->and($empty['headless_blocks'])->toBe([]);
});

it('does not compute the blocks of a list of posts that did not ask for them', function (): void {
    (new BlocksField)->register(new WordPressHooks);
    $GLOBALS['wp_rest_server'] = null;
    rest_get_server();
    $id = wp_insert_post(['post_title' => 'Plain', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 'post_status' => 'publish']);

    $request = new WP_REST_Request('GET', '/wp/v2/posts');
    $request->set_param('_fields', 'id,title');
    $data = rest_do_request($request)->get_data();
    wp_delete_post($id, true);

    expect($data[0])->not->toHaveKey('headless_blocks');
});
