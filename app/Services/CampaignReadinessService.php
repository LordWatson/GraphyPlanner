<?php

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Enums\DeliverableState;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\SocialAccount;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step C-4.5 — how ready a campaign is to run: the share of its deliverables that are approved or
 * further along, weighted by how soon they're due, plus the blockers in plain words. Derived,
 * never stored (plan §1.3.2).
 *
 * Weighting: work due within 7 days (or overdue) counts 3×, within 21 days 2×, later or undated
 * 1×, so a campaign whose launch posts are signed off reads as readier than one whose far-off
 * posts are. A campaign with no deliverables is "Not planned", never 0% or 100%.
 *
 * `computeMany()` costs two queries for any number of campaigns (one org).
 */
class CampaignReadinessService
{
    public const SOON_DAYS = 7;

    public const NEAR_DAYS = 21;

    public function compute(Campaign $campaign, ?CarbonImmutable $today = null): CampaignReadiness
    {
        return $this->computeMany(new Collection([$campaign]), $today)[$campaign->id];
    }

    /**
     * @param  Collection<int, Campaign>  $campaigns
     * @return array<int, CampaignReadiness> keyed by campaign id
     */
    public function computeMany(Collection $campaigns, ?CarbonImmutable $today = null): array
    {
        if ($campaigns->isEmpty()) {
            return [];
        }

        $today ??= CampaignClock::today($campaigns->first());

        $rows = DB::table('campaign_deliverables')
            ->leftJoin('posts', function ($join) {
                $join->on('posts.id', '=', 'campaign_deliverables.post_id')->whereNull('posts.deleted_at');
            })
            ->whereIn('campaign_deliverables.campaign_id', $campaigns->modelKeys())
            ->get([
                'campaign_deliverables.campaign_id',
                'campaign_deliverables.platform',
                'campaign_deliverables.planned_for',
                'campaign_deliverables.asset_requirements',
                'posts.status as post_status',
            ])
            ->groupBy('campaign_id');

        $connected = SocialAccount::query()->withoutOrgScope()
            ->whereIn('client_id', $campaigns->pluck('client_id')->unique()->all())
            ->where('connection_status', ConnectionStatus::Connected)
            ->get(['client_id', 'platform'])
            ->groupBy('client_id')
            ->map(fn ($accounts) => $accounts->map(fn (SocialAccount $account) => $account->platform->value)->unique()->all());

        $results = [];

        foreach ($campaigns as $campaign) {
            $results[$campaign->id] = $this->evaluate(
                $campaign,
                array_map(fn (object $row): array => (array) $row, $rows->get($campaign->id, collect())->all()),
                $connected->get($campaign->client_id, []),
                $today,
            );
        }

        return $results;
    }

    /**
     * @param  array<int, array<mixed>>  $deliverables  `platform`, `planned_for`, `asset_requirements`, `post_status`
     * @param  array<int, string>  $connectedPlatforms
     */
    private function evaluate(Campaign $campaign, array $deliverables, array $connectedPlatforms, CarbonImmutable $today): CampaignReadiness
    {
        if ($deliverables === []) {
            return CampaignReadiness::notPlanned();
        }

        $weighted = 0;
        $weightedReady = 0;
        $counts = array_fill_keys(array_map(fn (DeliverableState $state) => $state->value, DeliverableState::cases()), 0);
        $unmetRequirements = 0;
        $platformsNeeded = [];

        foreach ($deliverables as $row) {
            $postStatus = $row['post_status'] !== null ? PostStatus::tryFrom((string) $row['post_status']) : null;
            $state = DeliverableState::fromPost($postStatus);
            $counts[$state->value]++;

            if ($state === DeliverableState::Dropped) {
                continue;
            }

            $weight = $this->weight($row['planned_for'] !== null ? CarbonImmutable::parse((string) $row['planned_for'], $today->getTimezone()) : null, $today);
            $weighted += $weight;
            $weightedReady += $state->isReady() ? $weight : 0;

            $platformsNeeded[(string) $row['platform']] = true;

            foreach ((array) json_decode((string) ($row['asset_requirements'] ?? '[]'), true) as $requirement) {
                if (is_array($requirement) && ! ($requirement['met'] ?? false)) {
                    $unmetRequirements++;
                }
            }
        }

        $percent = $weighted === 0 ? 0 : (int) round($weightedReady / $weighted * 100);

        return new CampaignReadiness(
            percent: $percent,
            blockers: $this->blockers($campaign, $counts, $unmetRequirements, array_keys($platformsNeeded), $connectedPlatforms, $today),
            counts: $counts,
        );
    }

    private function weight(?CarbonImmutable $plannedFor, CarbonImmutable $today): int
    {
        if ($plannedFor === null) {
            return 1;
        }

        return match (true) {
            $plannedFor->lessThanOrEqualTo($today->addDays(self::SOON_DAYS)) => 3,
            $plannedFor->lessThanOrEqualTo($today->addDays(self::NEAR_DAYS)) => 2,
            default => 1,
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $platformsNeeded
     * @param  array<int, string>  $connectedPlatforms
     * @return list<array{code: string, reason: string}>
     */
    private function blockers(Campaign $campaign, array $counts, int $unmetRequirements, array $platformsNeeded, array $connectedPlatforms, CarbonImmutable $today): array
    {
        $launch = $this->launchPhrase($campaign, $today);
        $blockers = [];

        foreach ($platformsNeeded as $platform) {
            if (! in_array($platform, $connectedPlatforms, true)) {
                $label = Platform::tryFrom($platform)?->label() ?? $platform;
                $blockers[] = ['code' => 'account_missing', 'reason' => "No {$label} account connected."];
            }
        }

        if ($counts[DeliverableState::NotStarted->value] > 0) {
            $n = $counts[DeliverableState::NotStarted->value];
            $blockers[] = ['code' => 'not_started', 'reason' => ($n === 1 ? '1 deliverable not started' : "{$n} deliverables not started").$launch];
        }

        if ($counts[DeliverableState::InReview->value] > 0) {
            $n = $counts[DeliverableState::InReview->value];
            $blockers[] = ['code' => 'in_review', 'reason' => ($n === 1 ? '1 post still in review' : "{$n} posts still in review").$launch];
        }

        if ($counts[DeliverableState::Failed->value] > 0) {
            $n = $counts[DeliverableState::Failed->value];
            $blockers[] = ['code' => 'failed', 'reason' => $n === 1 ? '1 post failed to publish.' : "{$n} posts failed to publish."];
        }

        if ($unmetRequirements > 0) {
            $blockers[] = ['code' => 'assets_unmet', 'reason' => $unmetRequirements === 1 ? '1 asset requirement unmet.' : "{$unmetRequirements} asset requirements unmet."];
        }

        return $blockers;
    }

    private function launchPhrase(Campaign $campaign, CarbonImmutable $today): string
    {
        if ($campaign->start_date === null) {
            return '.';
        }

        $start = CarbonImmutable::parse($campaign->start_date->toDateString(), $today->getTimezone());

        if ($start->lessThan($today)) {
            return '.';
        }

        $days = (int) $today->diffInDays($start);

        return match ($days) {
            0 => ', launch today.',
            1 => ', launch tomorrow.',
            default => ", launch in {$days} days.",
        };
    }
}
