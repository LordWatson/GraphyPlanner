<?php

namespace App\Services\Grids;

use App\Models\GridPlan;
use App\Models\GridTile;

/**
 * Step 18.10 — grid posts must go out in publish order (the last position first), each at least
 * `grids.min_gap_minutes` after the one before, so the vendor can't swap two close posts. A move
 * that breaks this (the calendar, the post editor) is still allowed; it's reported here — the
 * grid checklist's `dates` item, the planner and the post editor show the warning.
 */
class GridSequenceValidator
{
    /**
     * @return list<array{message: string, tile_ids: list<int>}>
     */
    public static function violations(GridPlan $plan, GridPlanService $grids = new GridPlanService): array
    {
        $grids->load($plan);
        $gap = max(0, (int) config('grids.min_gap_minutes', 10));

        $timed = $plan->tiles
            ->sortByDesc('position')
            ->map(fn (GridTile $tile) => ['tile' => $tile, 'at' => $grids->publishAt($plan, $tile)])
            ->filter(fn (array $entry) => $entry['at'] !== null)
            ->values();

        $violations = [];

        for ($i = 1; $i < $timed->count(); $i++) {
            $before = $timed[$i - 1];
            $after = $timed[$i];
            /** @var GridTile $earlier */
            $earlier = $before['tile'];
            /** @var GridTile $later */
            $later = $after['tile'];

            if ($after['at']->lessThanOrEqualTo($before['at'])) {
                $violations[] = [
                    'message' => "This puts tile {$later->position} before tile {$earlier->position}. The grid will be out of order.",
                    'tile_ids' => [$earlier->id, $later->id],
                ];
            } elseif ($before['at']->diffInMinutes($after['at']) < $gap) {
                $violations[] = [
                    'message' => "Tiles {$earlier->position} and {$later->position} go out less than {$gap} minutes apart. Leave more room so they can't swap.",
                    'tile_ids' => [$earlier->id, $later->id],
                ];
            }
        }

        return $violations;
    }

    /**
     * The violations involving one tile — the post editor's warning for a post made from it.
     *
     * @return list<string>
     */
    public static function forTile(GridPlan $plan, GridTile $tile, GridPlanService $grids = new GridPlanService): array
    {
        return array_values(array_map(
            fn (array $violation) => $violation['message'],
            array_filter(self::violations($plan, $grids), fn (array $violation) => in_array($tile->id, $violation['tile_ids'], true)),
        ));
    }
}
