<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\User;
use App\Support\Calendar\CalendarWindow;
use Illuminate\Database\Eloquent\Builder;

/**
 * Step C-3.5 — the calendar's campaign planning layer (the first of I-5.2's planning layers;
 * reuse it there): a band per campaign that overlaps the visible window, its phases as sub-bands,
 * and the planned-but-not-started deliverables as "ghost" items so gaps in the plan show up next
 * to real posts. Staff calendar only — the portal calendar never asks for it, and ghosts are
 * internal by definition.
 */
class GetCalendarCampaignLayerAction
{
    /**
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     * @return array{bands: list<array<string, mixed>>, ghosts: list<array<string, mixed>>}
     */
    public function __invoke(User $user, array $filters, CalendarWindow $window): array
    {
        $from = $window->from->toDateString();
        $to = $window->to->toDateString();

        $campaigns = Campaign::query()
            ->where('org_id', $user->org_id)
            ->whereNotIn('status', [CampaignStatus::Archived])
            ->whereNotNull('start_date')
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->when($filters['client_id'] ?? null, fn (Builder $query, $id) => $query->where('client_id', $id))
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
            ->with(['client:id,name', 'phases' => fn ($query) => $query->whereNotNull('starts_on')])
            ->orderBy('start_date')
            ->limit(40)
            ->get();

        $ghosts = CampaignDeliverable::query()
            ->whereIn('campaign_id', Campaign::query()
                ->where('org_id', $user->org_id)
                ->whereNotIn('status', [CampaignStatus::Archived, CampaignStatus::Completed])
                ->when($filters['client_id'] ?? null, fn (Builder $query, $id) => $query->where('client_id', $id))
                ->when($filters['campaign_id'] ?? null, fn (Builder $query, $id) => $query->whereKey($id))
                ->select('id'))
            ->whereNull('post_id')
            ->whereBetween('planned_for', [$from, $to])
            ->when($filters['platform'] ?? null, fn (Builder $query, $platform) => $query->where('platform', $platform))
            ->with('campaign:id,name,code,colour,client_id')
            ->orderBy('planned_for')
            ->orderBy('planned_time')
            ->limit(300)
            ->get();

        return [
            'bands' => array_values($campaigns->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'code' => $campaign->code,
                'colour' => $campaign->colour?->value,
                'client_name' => $campaign->client?->name,
                'start_date' => $campaign->start_date?->toDateString(),
                'end_date' => $campaign->end_date?->toDateString(),
                'phases' => array_values($campaign->phases->map(fn (CampaignPhase $phase) => [
                    'name' => $phase->name,
                    'starts_on' => $phase->starts_on?->toDateString(),
                    'ends_on' => $phase->ends_on?->toDateString(),
                ])->all()),
            ])->all()),
            'ghosts' => array_values($ghosts->map(fn (CampaignDeliverable $deliverable) => [
                'id' => $deliverable->id,
                'campaign_id' => $deliverable->campaign_id,
                'campaign_code' => $deliverable->campaign->code,
                'campaign_colour' => $deliverable->campaign->colour?->value,
                'title' => $deliverable->title,
                'platform' => $deliverable->platform->value,
                'date' => $deliverable->planned_for?->toDateString(),
                'time' => $deliverable->planned_time,
            ])->all()),
        ];
    }
}
