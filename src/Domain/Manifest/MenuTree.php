<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Manifest;

/**
 * Turns the flat list of a menu's items into the tree a front end renders.
 *
 * WordPress gives every item its parent's ID and its position; the tree keeps the order of the editor, puts an
 * item whose parent is not in the list (a parent that was deleted) at the top, and cannot loop on a bad parent.
 */
final class MenuTree
{
    /**
     * @param  list<array{id: int, parent: int, order: int}&array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function build(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);

        $ids = array_column($items, 'id');
        $children = [];

        foreach ($items as $item) {
            $parent = $item['parent'] !== $item['id'] && in_array($item['parent'], $ids, true) ? $item['parent'] : 0;
            $children[$parent][] = $item;
        }

        // Items that only point at each other (A under B under A) are reachable from no top: the first of them becomes one
        while (($lost = array_values(array_diff($ids, self::reached($children)))) !== []) {
            $first = array_values(array_filter($items, static fn (array $item): bool => $item['id'] === $lost[0]))[0];
            $children[$first['parent']] = array_values(array_filter($children[$first['parent']] ?? [], static fn (array $item): bool => $item['id'] !== $first['id']));
            $children[0][] = $first;
        }

        return self::level($children, 0, []);
    }

    /**
     * @param  array<int, list<array{id: int, parent: int, order: int}&array<string, mixed>>>  $children
     * @return list<int>
     */
    private static function reached(array $children): array
    {
        $reached = [];
        $queue = [0];

        while ($queue !== []) {
            $parent = array_shift($queue);

            foreach ($children[$parent] ?? [] as $item) {
                if (! in_array($item['id'], $reached, true)) {
                    $reached[] = (int) $item['id'];
                    $queue[] = (int) $item['id'];
                }
            }
        }

        return $reached;
    }

    /**
     * @param  array<int, list<array{id: int, parent: int, order: int}&array<string, mixed>>>  $children
     * @param  list<int>  $path  the items above this level, so that a cycle (A under B under A) ends
     * @return list<array<string, mixed>>
     */
    private static function level(array $children, int $parent, array $path): array
    {
        $level = [];

        foreach ($children[$parent] ?? [] as $item) {
            $id = (int) $item['id'];

            if (in_array($id, $path, true)) {
                continue;
            }

            unset($item['parent'], $item['order']);
            $item['children'] = self::level($children, $id, [...$path, $id]);
            $level[] = $item;
        }

        return $level;
    }
}
