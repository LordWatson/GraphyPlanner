<?php

namespace App\Services\Grids;

use App\Models\GridPlan;
use App\Support\Grids\GridLayout;
use App\Support\Grids\GridLayoutTile;
use App\Support\Grids\GridOutsidePost;

/**
 * Step 18.2 — the one place grid ordering is worked out (pure, no I/O). Position 1 is top-left
 * and newest; the grid publishes in reverse. Given the tiles, the plan's pinned post count and
 * the outside posts that land among them (Step 18.11), it returns every tile's slot, row and
 * column, its publish sequence ("Goes out 3rd"), and whether each split group fills whole rows.
 *
 * Mirrored by `gridSlots()` in `resources/js/lib/grid-layout.ts`; both run the shared fixture
 * `tests/Fixtures/grid-layout.json`.
 */
class GridLayoutService
{
    /**
     * @param  list<GridLayoutTile>  $tiles
     * @param  list<GridOutsidePost>  $outside
     */
    public function layout(array $tiles, int $pinnedCount = 0, array $outside = []): GridLayout
    {
        $pinnedCount = max(0, min(GridPlan::MAX_PINNED, $pinnedCount));

        usort($tiles, fn (GridLayoutTile $a, GridLayoutTile $b) => $a->position <=> $b->position);
        usort($outside, fn (GridOutsidePost $a, GridOutsidePost $b) => $b->publishAt <=> $a->publishAt ?: $a->postId <=> $b->postId);

        // Newest first: an outside post sits in front of the first tile it's newer than. Tiles
        // without a time can't be compared, so outside posts slide past them.
        $entries = [];

        foreach ($tiles as $tile) {
            if ($tile->publishAt !== null) {
                while ($outside !== [] && $outside[0]->publishAt->greaterThan($tile->publishAt)) {
                    $entries[] = ['kind' => 'outside', 'id' => array_shift($outside)->postId];
                }
            }

            $entries[] = ['kind' => 'tile', 'id' => $tile->id];
        }

        // Anything left is older than every dated tile — it sits below the plan.
        foreach ($outside as $post) {
            $entries[] = ['kind' => 'outside', 'id' => $post->postId];
        }

        $count = count($tiles);
        $sequence = [];

        foreach ($tiles as $index => $tile) {
            $sequence[$tile->id] = $count - $index;
        }

        $slots = [];

        foreach ($entries as $index => $entry) {
            $slot = $pinnedCount + $index;

            $slots[] = [
                'kind' => $entry['kind'],
                'id' => $entry['id'],
                'slot' => $slot,
                'row' => intdiv($slot, GridPlan::COLUMNS) + 1,
                'column' => $slot % GridPlan::COLUMNS + 1,
                'sequence' => $entry['kind'] === 'tile' ? $sequence[$entry['id']] : null,
            ];
        }

        return new GridLayout($slots, $this->splitGroups($tiles, $slots), $pinnedCount);
    }

    /**
     * A split group lines up when its tiles sit side by side, start a row and fill whole rows.
     * `offset` is how far its first tile is from the start of its row (0 when aligned at the start).
     *
     * @param  list<GridLayoutTile>  $tiles
     * @param  list<array{kind: 'tile'|'outside', id: int, slot: int, row: int, column: int, sequence: int|null}>  $slots
     * @return list<array{id: string, tile_ids: list<int>, first_slot: int, rows: list<int>, aligned: bool, offset: int}>
     */
    private function splitGroups(array $tiles, array $slots): array
    {
        $slotOf = [];

        foreach ($slots as $slot) {
            if ($slot['kind'] === 'tile') {
                $slotOf[$slot['id']] = $slot['slot'];
            }
        }

        $groups = [];

        foreach ($tiles as $tile) {
            if ($tile->splitGroupId !== null) {
                $groups[$tile->splitGroupId][] = $tile->id;
            }
        }

        $result = [];

        foreach ($groups as $id => $tileIds) {
            $groupSlots = array_map(fn (int $tileId) => $slotOf[$tileId], $tileIds);
            $first = min($groupSlots);
            $last = max($groupSlots);
            $size = count($tileIds);

            $result[] = [
                'id' => (string) $id,
                'tile_ids' => $tileIds,
                'first_slot' => $first,
                'rows' => array_values(array_unique(array_map(fn (int $slot) => intdiv($slot, GridPlan::COLUMNS) + 1, $groupSlots))),
                'aligned' => $last - $first + 1 === $size && $size % GridPlan::COLUMNS === 0 && $first % GridPlan::COLUMNS === 0,
                'offset' => $first % GridPlan::COLUMNS,
            ];
        }

        return $result;
    }
}
