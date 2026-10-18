<?php

namespace App\Services;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\CampaignStatus;
use App\Enums\ClientHealth;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignHealth;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Step C-0.4 — a campaign's health (Red/Amber/Green) with the reasons behind it. Derived, never
 * stored (plan §1.3.2), the same way `ClientHealthService` works.
 *
 * Rules are pluggable (`CampaignHealthRule`, tagged `campaign.health-rules`), and are primed once
 * per batch, so health for a page of 25 campaigns costs a fixed handful of grouped queries rather
 * than a few per campaign. Completed and archived campaigns are never at risk.
 */
class CampaignHealthService
{
    /**
     * @param  iterable<CampaignHealthRule>  $rules
     */
    public function __construct(private readonly iterable $rules) {}

    public function compute(Campaign $campaign, ?CarbonImmutable $today = null): CampaignHealth
    {
        return $this->computeMany(new Collection([$campaign]), $today)[$campaign->id];
    }

    /**
     * @param  Collection<int, Campaign>  $campaigns  all from one organization
     * @return array<int, CampaignHealth> keyed by campaign id
     */
    public function computeMany(Collection $campaigns, ?CarbonImmutable $today = null): array
    {
        if ($campaigns->isEmpty()) {
            return [];
        }

        $context = new CampaignHealthContext($today ?? CampaignClock::today($campaigns->first()));
        $open = $campaigns->reject(fn (Campaign $campaign) => $this->isClosed($campaign))->values();

        if ($open->isNotEmpty()) {
            foreach ($this->rules as $rule) {
                $rule->prime($open, $context);
            }
        }

        $results = [];

        foreach ($campaigns as $campaign) {
            $results[$campaign->id] = $this->evaluate($campaign, $context);
        }

        return $results;
    }

    private function evaluate(Campaign $campaign, CampaignHealthContext $context): CampaignHealth
    {
        if ($this->isClosed($campaign)) {
            return new CampaignHealth(ClientHealth::Green, $campaign->status === CampaignStatus::Completed
                ? 'Campaign wrapped.'
                : 'Archived. Nothing at risk.', []);
        }

        $findings = [];

        foreach ($this->rules as $rule) {
            if ($finding = $rule->evaluate($campaign, $context)) {
                $findings[] = $finding;
            }
        }

        // Red first, then amber, keeping each rule's own order within a level.
        usort($findings, fn (CampaignHealthFinding $a, CampaignHealthFinding $b) => $this->rank($a->level) <=> $this->rank($b->level));

        if ($findings === []) {
            return new CampaignHealth(ClientHealth::Green, 'On track.', []);
        }

        return new CampaignHealth($findings[0]->level, $findings[0]->reason, $findings);
    }

    private function isClosed(Campaign $campaign): bool
    {
        return in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Archived], true);
    }

    private function rank(ClientHealth $level): int
    {
        return match ($level) {
            ClientHealth::Red => 0,
            ClientHealth::Amber => 1,
            ClientHealth::Green => 2,
        };
    }
}
