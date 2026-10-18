<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\User;
use App\Support\Campaigns\SuggestedPhases;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.1 — "Use suggested phases": creates Teaser · Launch · Sustain · Wrap across a dated
 * campaign with no phases yet, in one transaction. Only on a click, never automatically.
 */
class ApplySuggestedPhasesAction
{
    /**
     * @return list<CampaignPhase>
     */
    public function __invoke(Campaign $campaign, ?User $user): array
    {
        if ($campaign->start_date === null || $campaign->end_date === null) {
            throw ValidationException::withMessages(['phases' => 'Set the campaign’s start and end dates first.']);
        }

        return DB::transaction(function () use ($campaign, $user) {
            if ($campaign->phases()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['phases' => 'This campaign already has phases.']);
            }

            $phases = [];

            foreach (SuggestedPhases::between(CarbonImmutable::parse($campaign->start_date), CarbonImmutable::parse($campaign->end_date)) as $index => $phase) {
                $phases[] = $campaign->phases()->create([...$phase, 'sort' => $index + 1]);
            }

            $campaign->recordActivity('phases.updated', $user, meta: ['suggested' => true, 'count' => count($phases)]);

            Log::info('Suggested campaign phases applied', [
                'campaign_id' => $campaign->id,
                'user_id' => $user?->id,
                'phase_count' => count($phases),
            ]);

            return $phases;
        });
    }
}
