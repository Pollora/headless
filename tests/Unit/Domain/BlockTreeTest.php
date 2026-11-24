<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Block\BlockTree;

/**
 * @param  list<array<string, mixed>>  $parsed
 * @return list<array<string, mixed>>
 */
function blocksOf(array $parsed, array $defaults = [], array $dynamic = []): array
{
    return BlockTree::from(
        $parsed,
        static fn (string $name, array $attrs): array => [...$attrs, ...array_diff_key($defaults[$name] ?? [], $attrs)],
        static fn (array $block): string => 'rendered:'.($block['blockName'] ?? 'freeform'),
        static fn (string $name): bool => in_array($name, $dynamic, true),
    );
}

function parsedBlock(?string $name, string $html = '', array $attrs = [], array $inner = []): array
{
    return ['blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $inner, 'innerHTML' => $html, 'innerContent' => [$html]];
}

it('gives every block the same keys in the same order', function (): void {
    $blocks = blocksOf([parsedBlock('core/paragraph', '<p>Hello</p>', ['dropCap' => true])]);

    expect(array_keys($blocks[0]))->toBe(['id', 'name', 'attributes', 'html', 'rendered', 'inner_blocks'])
        ->and($blocks[0])->toMatchArray(['id' => '0', 'name' => 'core/paragraph', 'attributes' => ['dropCap' => true], 'html' => '<p>Hello</p>', 'rendered' => 'rendered:core/paragraph', 'inner_blocks' => []]);
});

it('numbers the blocks by their position, through the nesting', function (): void {
    $blocks = blocksOf([
        parsedBlock('core/heading', '<h2>A</h2>'),
        parsedBlock('core/group', '<div></div>', [], [parsedBlock('core/paragraph', '<p>B</p>'), parsedBlock('core/columns', '', [], [parsedBlock('core/column', '', [], [parsedBlock('core/image', '<img>')])])]),
    ]);

    expect(array_column($blocks, 'id'))->toBe(['0', '1'])
        ->and(array_column($blocks[1]['inner_blocks'], 'id'))->toBe(['1.0', '1.1'])
        ->and($blocks[1]['inner_blocks'][1]['inner_blocks'][0]['inner_blocks'][0]['id'])->toBe('1.1.0.0');
});

it('says what WordPress prints for the top-level blocks and for the dynamic ones only', function (): void {
    $blocks = blocksOf([
        parsedBlock('core/group', '<div></div>', [], [parsedBlock('core/paragraph', '<p>Static</p>'), parsedBlock('core/latest-posts', '', [], [])]),
    ], [], ['core/latest-posts']);

    expect($blocks[0])->toHaveKey('rendered')
        ->and($blocks[0]['inner_blocks'][0])->not->toHaveKey('rendered')
        ->and($blocks[0]['inner_blocks'][1]['rendered'])->toBe('rendered:core/latest-posts');
});

it('completes the attributes with the defaults of the block type, and keeps what the comment says', function (): void {
    $blocks = blocksOf([parsedBlock('core/image', '', ['id' => 5])], ['core/image' => ['id' => 0, 'sizeSlug' => 'large']]);

    expect($blocks[0]['attributes'])->toBe(['id' => 5, 'sizeSlug' => 'large']);
});

it('turns the text between blocks into a freeform block, and drops whitespace', function (): void {
    $blocks = blocksOf([parsedBlock(null, "\n\n"), parsedBlock(null, '<p>Classic content</p>'), parsedBlock('core/paragraph', '<p>x</p>'), parsedBlock(null, '   ')]);

    expect(array_column($blocks, 'name'))->toBe(['core/freeform', 'core/paragraph'])
        ->and(array_column($blocks, 'id'))->toBe(['0', '1']);
});

it('gives no attributes as an empty object, so that the JSON stays an object', function (): void {
    $blocks = blocksOf([parsedBlock('core/separator', '<hr>')]);

    expect($blocks[0]['attributes'])->toBeInstanceOf(stdClass::class)
        ->and(json_encode($blocks[0]['attributes']))->toBe('{}');
});

it('reads a parsed block that is incomplete', function (): void {
    $blocks = blocksOf([['blockName' => 'core/quote'], ['blockName' => 'core/list', 'attrs' => 'broken', 'innerBlocks' => 'broken', 'innerHTML' => 5]]);

    expect(array_column($blocks, 'name'))->toBe(['core/quote', 'core/list'])
        ->and($blocks[1]['inner_blocks'])->toBe([])
        ->and($blocks[1]['html'])->toBe('');
});

it('gives nothing for a content without blocks', function (): void {
    expect(blocksOf([]))->toBe([]);
});
