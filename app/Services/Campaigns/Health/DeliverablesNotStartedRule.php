<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Step C-3.2 — amber: launch is within 7 days and fewer than half of the planned deliverables
 * have been started.
 */
class DeliverablesNotStartedRule implements CampaignHealthRule
{
    public const DAYS = 7;

    private const FACT = 'deliverables.progress';

    public function prime(Collection $campaigns, CampaignHealthContext $context): void
    {
        $ids = $campaigns->filter(fn (Campaign $campaign) => $this->launchesWithin($campaign, $context->today))->modelKeys();

        if ($ids === []) {
            $context->put(self::FACT, []);

            return;
        }

        $rows = CampaignDeliverable::query()
            ->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, count(*) as total, count(post_id) as started')
            ->groupBy('campaign_id')
            ->toBase()
            ->get();

        $facts = [];

        foreach ($rows as $row) {
            $facts[(int) $row->campaign_id] = ['total' => (int) $row->total, 'started' => (int) $row->started];
        }

        $context->put(self::FACT, $facts);
    }

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        /** @var array{total: int, started: int}|null $progress */
        $progress = $context->get(self::FACT, $campaign->id);

        if ($progress === null || $progress['total'] === 0 || $progress['total'] <= $progress['started'] * 2) {
            return null;
        }

        return CampaignHealthFinding::amber(
            'deliverables_not_started',
            "Launches within a week and only {$progress['started']} of {$progress['total']} deliverables are started.",
        );
    }

    private function launchesWithin(Campaign $campaign, CarbonImmutable $today): bool
    {
        if ($campaign->start_date === null || ! in_array($campaign->status, [CampaignStatus::Planning, CampaignStatus::Active], true)) {
            return false;
        }

        $start = CarbonImmutable::parse($campaign->start_date->toDateString(), $today->getTimezone());

        return $start->greaterThanOrEqualTo($today) && $start->lessThanOrEqualTo($today->addDays(self::DAYS));
    }
}
