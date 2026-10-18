<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Services\CampaignHealthService;
use App\Services\CampaignReadinessService;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use App\Support\Campaigns\CampaignTimeline;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step C-1.4 — the Overview's "Live campaigns" panel: campaigns running today (by their dates,
 * Active or Paused), ending soonest first, each with its timeline line, readiness and health. A
 * fixed number of queries whatever the count (health and readiness are batched).
 */
class GetLiveCampaignsAction
{
    public const LIMIT = 6;

    public function __construct(
        private readonly CampaignHealthService $health,
        private readonly CampaignReadinessService $readiness,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function __invoke(User $user): array
    {
        $today = CampaignClock::today($user->organization);
        $date = $today->toDateString();

        $query = Campaign::query()
            ->where('org_id', $user->org_id)
            ->whereIn('client_id', Client::query()->select('id'))
            ->whereIn('status', [CampaignStatus::Active, CampaignStatus::Paused])
            ->where('start_date', '<=', $date)
            ->where(fn (Builder $inner) => $inner->whereNull('end_date')->orWhere('end_date', '>=', $date));

        $total = (clone $query)->count();
        $campaigns = $query->with(['client:id,name', 'organization:id,default_timezone'])
            ->orderByRaw('end_date is null')
            ->orderBy('end_date')
            ->limit(self::LIMIT)
            ->get();

        $health = $this->health->computeMany($campaigns, $today);
        $readiness = $this->readiness->computeMany($campaigns, $today);

        return [
            'items' => array_values($campaigns->map(fn (Campaign $campaign) => [
                ...CampaignPresenter::chip($campaign),
                'status' => $campaign->status->value,
                'status_label' => $campaign->status->label(),
                'client_name' => $campaign->client?->name,
                'timeline' => CampaignTimeline::for($campaign, $today)->toArray(),
                'health' => $health[$campaign->id]->toArray(),
                'readiness' => $readiness[$campaign->id]->toArray(),
            ])->all()),
            'total' => $total,
        ];
    }
}
