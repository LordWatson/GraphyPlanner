<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Exceptions\Campaigns\InvalidCampaignTransitionException;
use App\Models\Campaign;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-0.3 — move a campaign to a new status, guarded by `CampaignStatusTransitionMap`.
 *
 * The row is locked and the move re-checked against the *locked* status (the I-1.4 pattern), so
 * two people pressing "Pause" and "Complete" at once can't both win. Exactly one
 * `status.changed` activity row is written per transition. `$within` lets a caller (pause,
 * resume) do its own writes inside the same transaction, against the locked campaign; anything
 * that leaves the database must be registered with `DB::afterCommit()`.
 *
 * Callers authorize via `CampaignPolicy::transition` first.
 */
class TransitionCampaignStatusAction
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  (Closure(Campaign, CampaignStatus): array<string, mixed>)|null  $within
     */
    public function __invoke(Campaign $campaign, CampaignStatus $to, ?User $user, array $meta = [], ?Closure $within = null): Campaign
    {
        if (! CampaignStatusTransitionMap::isAllowed($campaign->status, $to)) {
            throw new InvalidCampaignTransitionException($campaign->id, $campaign->status, $to);
        }

        $locked = DB::transaction(function () use ($campaign, $to, $user, $meta, $within) {
            /** @var Campaign $locked */
            $locked = Campaign::query()->withoutOrgScope()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! CampaignStatusTransitionMap::isAllowed($from, $to)) {
                Log::warning('Campaign transition rejected: status changed concurrently', [
                    'campaign_id' => $locked->id,
                    'user_id' => $user?->id,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ]);

                throw new InvalidCampaignTransitionException($locked->id, $from, $to);
            }

            $locked->update(['status' => $to]);

            if ($within !== null) {
                $meta = [...$meta, ...$within($locked, $from)];
            }

            $locked->recordActivity('status.changed', $user, $from->value, $to->value, $meta === [] ? null : $meta);

            Log::info('Campaign status transitioned', [
                'campaign_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $user?->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);

            return $locked;
        });

        $campaign->setRawAttributes($locked->getAttributes(), true);

        return $campaign;
    }
}
