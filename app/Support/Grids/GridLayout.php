<?php

namespace App\Support\Grids;

/**
 * Step 18.2 — the result of `GridLayoutService::layout()`: where every plan tile (and every
 * outside post that lands among them) sits on the profile grid, its publish sequence, and whether
 * each split group fills whole rows.
 *
 * Slots are 0-based from the top-left of the profile, pinned posts included; rows and columns
 * are 1-based for display.
 */
final readonly class GridLayout
{
    /**
     * @param  list<array{kind: 'tile'|'outside', id: int, slot: int, row: int, column: int, sequence: int|null}>  $slots
     * @param  list<array{id: string, tile_ids: list<int>, first_slot: int, rows: list<int>, aligned: bool, offset: int}>  $splitGroups
     */
    public function __construct(
        public array $slots,
        public array $splitGroups,
        public int $pinnedCount,
    ) {}

    /**
     * @return array{kind: 'tile'|'outside', id: int, slot: int, row: int, column: int, sequence: int|null}|null
     */
    public function tile(int $tileId): ?array
    {
        foreach ($this->slots as $slot) {
            if ($slot['kind'] === 'tile' && $slot['id'] === $tileId) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Outside posts that land among (or in front of) the plan's tiles.
     *
     * @return list<array{kind: 'tile'|'outside', id: int, slot: int, row: int, column: int, sequence: int|null}>
     */
    public function outside(): array
    {
        return array_values(array_filter($this->slots, fn (array $slot) => $slot['kind'] === 'outside'));
    }

    /**
     * @return list<array{id: string, tile_ids: list<int>, first_slot: int, rows: list<int>, aligned: bool, offset: int}>
     */
    public function misalignedSplits(): array
    {
        return array_values(array_filter($this->splitGroups, fn (array $group) => ! $group['aligned']));
    }

    /**
     * @return array{pinned_count: int, slots: list<array<string, mixed>>, split_groups: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'pinned_count' => $this->pinnedCount,
            'slots' => $this->slots,
            'split_groups' => $this->splitGroups,
        ];
    }
}
