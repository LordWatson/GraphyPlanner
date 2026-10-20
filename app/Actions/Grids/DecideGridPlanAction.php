<?php

namespace App\Actions\Grids;

use App\Enums\GridPlanStatus;
use App\Models\GridPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DecideGridPlanAction
{
    public function __construct(
        private readonly TransitionGridPlanStatusAction $transition = new TransitionGridPlanStatusAction,
        private readonly CreateGridCommentAction $comment = new CreateGridCommentAction,
    ) {}

    /**
     * Step 18.8 — the client approves the whole grid or asks for changes, from the review link
     * (`$user` null) or the portal. `$version` is the grid version the client was looking at: if
     * it changed meanwhile, nothing happens (`StaleGridPlanException` — "This grid changed while you
     * were looking. Here's the latest."). Asking for changes can flag tiles, each becoming a
     * comment pinned to that tile; the overall note becomes a comment too.
     *
     * @param  array<int|string, string|null>  $flaggedTiles  `{tileId: note}`
     */
    public function __invoke(GridPlan $plan, ?User $user, GridPlanStatus $decision, int $version, ?string $note = null, array $flaggedTiles = []): GridPlan
    {
        return DB::transaction(function () use ($plan, $user, $decision, $version, $note, $flaggedTiles) {
            ($this->transition)($plan, $decision, $user, $note, $version);

            if (filled($note)) {
                ($this->comment)($plan, $user, (string) $note);
            }

            if ($decision === GridPlanStatus::ChangesRequested) {
                foreach ($flaggedTiles as $tileId => $tileNote) {
                    ($this->comment)($plan, $user, filled($tileNote) ? trim((string) $tileNote) : 'Flagged for changes.', tileId: (int) $tileId);
                }
            }

            Log::info('Client decided on grid plan', [
                'grid_plan_id' => $plan->id,
                'client_id' => $plan->client_id,
                'user_id' => $user?->id,
                'decision' => $decision->value,
                'flagged_tiles' => count($flaggedTiles),
            ]);

            return $plan;
        });
    }
}
