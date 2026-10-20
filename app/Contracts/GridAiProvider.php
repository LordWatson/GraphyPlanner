<?php

namespace App\Contracts;

use App\Models\GridPlan;

/**
 * Step 18.12 — the grid planner's AI help, kept apart from `AiProvider` (interface segregation,
 * like `CampaignAiProvider`). `DeepSeekProvider` and `NullAiProvider` implement it; the binding
 * follows the same AI-enabled + key rule. Suggestions only: nothing here writes, arranges or
 * schedules anything, and implementations never throw on a vendor failure (they return nothing).
 */
interface GridAiProvider
{
    /**
     * Caption ideas per tile, from each tile's description and the client's Brand Brain.
     *
     * @param  list<array{tile_id: int, position: int, kind: string, description: string}>  $tiles
     * @return array<int, list<string>> up to 2 caption ideas per tile id
     */
    public function suggestTileCaptions(GridPlan $plan, array $tiles): array;

    /**
     * Short notes on the grid's balance — repetition, colour runs, pillar mix — against the
     * Brand Brain's palette and pillars ("Three product shots in a row on row 2. Try moving tile
     * 5 up.").
     *
     * @param  list<array{position: int, row: int, kind: string, description: string, colours: list<string>}>  $tiles
     * @return list<string>
     */
    public function checkGridBalance(GridPlan $plan, array $tiles): array;
}
