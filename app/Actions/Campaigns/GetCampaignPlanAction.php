<?php

namespace App\Actions\Campaigns;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\DeliverableDueDate;
use App\Support\Campaigns\SuggestedPhases;
use Carbon\CarbonImmutable;

/**
 * Step C-3.1/C-3.2 — the Plan tab: phases in order, every deliverable with its derived state and
 * due date (C-4.4), the client's accounts per platform (for "Start post"), the team for
 * assignment, the Brand Brain pillars, and the suggested phase split when the campaign is dated
 * and has none. Four queries regardless of plan size.
 */
class GetCampaignPlanAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Campaign $campaign): array
    {
        $today = CampaignClock::today($campaign);
        $leadDays = (int) ($campaign->client->approval_lead_days ?? 3);

        $phases = $campaign->phases()->get();

        $deliverables = $campaign->deliverables()
            ->with(['post:id,status,deleted_at', 'assignee:id,name'])
            ->orderBy('planned_for')
            ->orderBy('planned_time')
            ->orderBy('sort')
            ->get();

        $accounts = SocialAccount::query()->withoutOrgScope()
            ->where('client_id', $campaign->client_id)
            ->orderBy('handle')
            ->get(['id', 'platform', 'handle', 'connection_status']);

        return [
            'phases' => $phases->map(fn (CampaignPhase $phase) => self::phase($phase))->values()->all(),
            'deliverables' => $deliverables->map(fn (CampaignDeliverable $deliverable) => self::deliverable($deliverable, $leadDays, $today))->values()->all(),
            'accounts' => $accounts->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'handle' => $account->handle,
                'connected' => $account->connection_status->value === 'connected',
            ])->values()->all(),
            'team' => User::query()
                ->where('org_id', $campaign->org_id)
                ->whereIn('role', ['owner', 'strategist', 'designer'])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
            'pillars' => array_values($campaign->client->brandBrain->content_pillars ?? []),
            'platformOptions' => array_map(fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()], Platform::cases()),
            'suggestedPhases' => $phases->isEmpty() && $campaign->start_date && $campaign->end_date
                ? SuggestedPhases::between(CarbonImmutable::parse($campaign->start_date->toDateString()), CarbonImmutable::parse($campaign->end_date->toDateString()))
                : [],
            'leadDays' => $leadDays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function phase(CampaignPhase $phase): array
    {
        return [
            'id' => $phase->id,
            'name' => $phase->name,
            'starts_on' => $phase->starts_on?->toDateString(),
            'ends_on' => $phase->ends_on?->toDateString(),
            'colour' => $phase->colour?->value,
            'sort' => $phase->sort,
        ];
    }

    /**
     * Expects `post` and `assignee` loaded.
     *
     * @return array<string, mixed>
     */
    public static function deliverable(CampaignDeliverable $deliverable, int $leadDays, CarbonImmutable $today): array
    {
        $state = $deliverable->state();
        $planned = $deliverable->planned_for ? CarbonImmutable::parse($deliverable->planned_for->toDateString(), $today->getTimezone()) : null;
        $due = DeliverableDueDate::for($planned, $leadDays);

        return [
            'id' => $deliverable->id,
            'phase_id' => $deliverable->campaign_phase_id,
            'title' => $deliverable->title,
            'platform' => $deliverable->platform->value,
            'format' => $deliverable->format,
            'pillar' => $deliverable->pillar,
            'planned_for' => $deliverable->planned_for?->toDateString(),
            'planned_time' => $deliverable->planned_time,
            'notes' => $deliverable->notes,
            'asset_requirements' => $deliverable->asset_requirements ?? [],
            'assignee' => $deliverable->assignee ? ['id' => $deliverable->assignee->id, 'name' => $deliverable->assignee->name] : null,
            'post_id' => $state->isStarted() ? $deliverable->post_id : null,
            'state' => $state->value,
            'state_label' => $state->label(),
            'due_on' => $due?->toDateString(),
            'is_overdue' => $due !== null && ! $state->isReady() && $due->lessThan($today),
        ];
    }
}
