<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Manifest\MenuTree;

function menuItem(int $id, int $parent = 0, int $order = 0, string $title = ''): array
{
    return ['id' => $id, 'parent' => $parent, 'order' => $order, 'title' => $title ?: "Item $id"];
}

/** @return list<array<string, mixed>> */
function titles(array $tree): array
{
    return array_map(static fn (array $node): array => $node['children'] === [] ? [$node['title']] : [$node['title'] => titles($node['children'])], $tree);
}

it('nests the items under their parent, in the editor\'s order', function (): void {
    $tree = MenuTree::build([
        menuItem(3, 1, 2, 'Team'),
        menuItem(1, 0, 1, 'About'),
        menuItem(2, 0, 3, 'Contact'),
        menuItem(4, 1, 1, 'History'),
        menuItem(5, 4, 1, 'Founders'),
    ]);

    expect(titles($tree))->toBe([['About' => [['History' => [['Founders']]], ['Team']]], ['Contact']]);
});

it('drops the fields that only served to build the tree', function (): void {
    $tree = MenuTree::build([menuItem(1, 0, 1)]);

    expect($tree[0])->toBe(['id' => 1, 'title' => 'Item 1', 'children' => []]);
});

it('puts an item whose parent is gone at the top', function (): void {
    $tree = MenuTree::build([menuItem(1, 0, 1), menuItem(2, 99, 2)]);

    expect(array_column($tree, 'id'))->toBe([1, 2]);
});

it('keeps a stable order for items at the same position', function (): void {
    $tree = MenuTree::build([menuItem(7, 0, 1), menuItem(5, 0, 1), menuItem(6, 0, 1)]);

    expect(array_column($tree, 'id'))->toBe([5, 6, 7]);
});

it('does not loop on an item that is its own parent, or on a cycle', function (): void {
    $self = MenuTree::build([menuItem(1, 1, 1)]);
    $cycle = MenuTree::build([menuItem(1, 2, 1), menuItem(2, 1, 2)]);

    expect(array_column($self, 'id'))->toBe([1])
        ->and($self[0]['children'])->toBe([])
        ->and(titles($cycle))->toBe([['Item 1' => [['Item 2']]]]);
});

it('builds an empty tree from no item', function (): void {
    expect(MenuTree::build([]))->toBe([]);
});
