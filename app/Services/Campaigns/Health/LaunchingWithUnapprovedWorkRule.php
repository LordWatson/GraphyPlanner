<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Red: the campaign launches within 3 days (today included, exactly 3 days out included) and
 * some of its work isn't approved yet — posts still before approval, plus planned deliverables
 * nobody has started (Step C-3.2).
 */
class LaunchingWithUnapprovedWorkRule implements CampaignHealthRule
{
    public const DAYS = 3;

    private const FACT = 'launch.unapproved';

    public function prime(Collection $campaigns, CampaignHealthContext $context): void
    {
        $ids = $campaigns
            ->filter(fn (Campaign $campaign) => $this->launchesSoon($campaign, $context->today))
            ->modelKeys();

        if ($ids === []) {
            $context->put(self::FACT, []);

            return;
        }

        $posts = Post::query()->withoutOrgScope()
            ->whereIn('campaign_id', $ids)
            ->whereIn('status', PostStatus::preApproval())
            ->selectRaw('campaign_id, count(*) as aggregate')
            ->groupBy('campaign_id')
            ->pluck('aggregate', 'campaign_id');

        $unstarted = CampaignDeliverable::query()
            ->whereIn('campaign_id', $ids)
            ->whereNull('post_id')
            ->selectRaw('campaign_id, count(*) as aggregate')
            ->groupBy('campaign_id')
            ->pluck('aggregate', 'campaign_id');

        $counts = [];

        foreach ($ids as $id) {
            $counts[$id] = (int) ($posts[$id] ?? 0) + (int) ($unstarted[$id] ?? 0);
        }

        $context->put(self::FACT, $counts);
    }

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        $count = (int) $context->get(self::FACT, $campaign->id, 0);

        if ($count === 0 || ! $this->launchesSoon($campaign, $context->today)) {
            return null;
        }

        $days = (int) $context->today->diffInDays(CarbonImmutable::parse($campaign->start_date?->toDateString(), $context->today->getTimezone()));
        $when = match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$days} days",
        };
        $items = $count === 1 ? '1 piece of work isn’t' : "{$count} pieces of work aren’t";

        return CampaignHealthFinding::red('launch_unapproved', "Launches {$when} and {$items} approved yet.");
    }

    private function launchesSoon(Campaign $campaign, CarbonImmutable $today): bool
    {
        if ($campaign->start_date === null || ! in_array($campaign->status, [CampaignStatus::Planning, CampaignStatus::Active], true)) {
            return false;
        }

        $start = CarbonImmutable::parse($campaign->start_date->toDateString(), $today->getTimezone());

        return $start->greaterThanOrEqualTo($today) && $start->lessThanOrEqualTo($today->addDays(self::DAYS));
    }
}
