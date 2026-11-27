<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Block;

use Closure;
use stdClass;

/**
 * The blocks of a post in a shape that does not change: the same keys on every block, in the same order, whatever
 * the block, so that a front end can be typed against it.
 *
 * ```
 * { id: "1.0", name: "core/paragraph", attributes: {…}, html: "<p>Hello</p>", rendered: "<p>Hello</p>", inner_blocks: [ … ] }
 * ```
 *
 * - `id` is the position of the block in the content (`1.0` is the first block inside the second one): stable for one
 *   content, and a key a front end can use to render a list.
 * - `attributes` are those of the block comment, completed with the defaults of the block type; `{}` when there are none.
 *   A value a block takes from its HTML (the text of a paragraph) is in `html`, as on the server it cannot be read otherwise.
 * - `html` is the block's own HTML, without its inner blocks. `rendered` is what WordPress prints for it, inner blocks
 *   included: it is present on top-level blocks and on dynamic blocks (a list of posts has no HTML of its own), absent otherwise.
 * - Text between blocks that is not whitespace is a `core/freeform` block.
 */
final class BlockTree
{
    /**
     * @param  array<mixed>  $parsed  what parse_blocks() returns
     * @param  Closure(string, array<mixed>): array<mixed>  $withDefaults  the attributes of a block, completed with its type's defaults
     * @param  Closure(array<mixed>): string  $render  the HTML WordPress prints for a parsed block
     * @param  Closure(string): bool  $isDynamic  whether a block type renders on the server
     * @return list<array<string, mixed>>
     */
    public static function from(array $parsed, Closure $withDefaults, Closure $render, Closure $isDynamic, string $prefix = ''): array
    {
        $blocks = [];

        foreach ($parsed as $block) {
            if (! is_array($block)) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;
            $html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

            if ($name === null && trim($html) === '') {
                continue;
            }

            $name ??= 'core/freeform';
            $id = $prefix.count($blocks);
            $attributes = $withDefaults($name, is_array($block['attrs'] ?? null) ? $block['attrs'] : []);

            $normalised = [
                'id' => $id,
                'name' => $name,
                'attributes' => $attributes === [] ? new stdClass : $attributes,
                'html' => $html,
            ];

            if ($prefix === '' || $isDynamic($name)) {
                $normalised['rendered'] = $render($block);
            }

            $normalised['inner_blocks'] = self::from(is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : [], $withDefaults, $render, $isDynamic, $id.'.');
            $blocks[] = $normalised;
        }

        return $blocks;
    }
}
