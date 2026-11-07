<?php

namespace App\Actions\Plans;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-0.1 — ends the agency grandfather. An org pinned to `agency_legacy` (unlimited, free)
 * when the paid tiers landed keeps it until `plan_grandfathered_until` passes; this moves it onto
 * `agency_standard` and clears the date.
 *
 * The whole point of the 12-month promise is that it expires, so it is a scheduled command rather
 * than a note in someone's calendar. Orgs whose date is still in the future are left alone, and an
 * org with no date keeps its plan indefinitely (that is how a permanent exemption is recorded).
 */
class EndPlanGrandfatherAction
{
    /**
     * @return array{moved: int, org_ids: list<int>}
     */
    public function __invoke(): array
    {
        $due = Organization::query()
            ->where('plan', 'agency_legacy')
            ->whereNotNull('plan_grandfathered_until')
            ->where('plan_grandfathered_until', '<=', now())
            ->get(['id', 'name', 'plan', 'plan_grandfathered_until']);

        if ($due->isEmpty()) {
            return ['moved' => 0, 'org_ids' => []];
        }

        $moved = [];

        DB::transaction(function () use ($due, &$moved) {
            foreach ($due as $organization) {
                $organization->forceFill([
                    'plan' => 'agency_standard',
                    'plan_grandfathered_until' => null,
                ])->save();

                $moved[] = (int) $organization->id;

                Log::warning('Agency grandfather ended; org moved to a paid tier', [
                    'org_id' => $organization->id,
                    'from_plan' => 'agency_legacy',
                    'to_plan' => 'agency_standard',
                ]);
            }
        });

        Log::info('Agency grandfather sweep complete', ['moved' => count($moved)]);

        return ['moved' => count($moved), 'org_ids' => $moved];
    }
}
