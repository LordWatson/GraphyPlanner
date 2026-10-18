<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use App\Models\CampaignTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.4 — "Save as template": turn a dated campaign's shape into an org template. Dates
 * become day offsets from the start; the brief keeps its structure with the client's name swapped
 * for `{client_name}`; client-specific details (code, hashtags, link, notes, owner, fee) are never
 * copied.
 */
class CreateCampaignTemplateAction
{
    public function __invoke(Campaign $campaign, string $name, ?string $description, User $user): CampaignTemplate
    {
        if ($campaign->start_date === null) {
            throw ValidationException::withMessages(['name' => 'Give the campaign a start date first, so its plan can be saved as offsets.']);
        }

        $start = CarbonImmutable::parse($campaign->start_date->toDateString());
        $end = $campaign->end_date ? CarbonImmutable::parse($campaign->end_date->toDateString()) : null;
        $clientName = (string) $campaign->client->name;
        $phases = $campaign->phases()->get()->values();
        $phaseIndex = $phases->pluck('id')->flip();

        $template = DB::transaction(function () use ($campaign, $name, $description, $user, $start, $end, $clientName, $phases, $phaseIndex) {
            $template = CampaignTemplate::create([
                'org_id' => $campaign->org_id,
                'created_by' => $user->id,
                'name' => $name,
                'description' => $description,
                'objective' => $campaign->objective,
                'duration_days' => $end ? (int) $start->diffInDays($end) + 1 : 28,
                'brief' => $this->brief($campaign->brief, $clientName),
                'phases' => array_values($phases->map(fn (CampaignPhase $phase) => [
                    'name' => $phase->name,
                    'start_offset' => $phase->starts_on ? (int) $start->diffInDays(CarbonImmutable::parse($phase->starts_on->toDateString()), false) : 0,
                    'length' => $phase->starts_on && $phase->ends_on ? (int) CarbonImmutable::parse($phase->starts_on->toDateString())->diffInDays(CarbonImmutable::parse($phase->ends_on->toDateString())) + 1 : 7,
                    'colour' => $phase->colour?->value,
                ])->all()),
                'deliverables' => array_values($campaign->deliverables()->orderBy('planned_for')->orderBy('sort')->get()->map(fn (CampaignDeliverable $deliverable) => [
                    'title' => $this->generalise($deliverable->title, $clientName),
                    'platform' => $deliverable->platform->value,
                    'format' => $deliverable->format,
                    'pillar' => $deliverable->pillar,
                    'phase_index' => $deliverable->campaign_phase_id !== null ? ($phaseIndex[$deliverable->campaign_phase_id] ?? null) : null,
                    'day_offset' => $deliverable->planned_for ? (int) $start->diffInDays(CarbonImmutable::parse($deliverable->planned_for->toDateString()), false) : 0,
                    'time' => $deliverable->planned_time,
                ])->all()),
            ]);

            $campaign->recordActivity('template.saved', $user, meta: ['template_id' => $template->id]);

            return $template;
        });

        Log::info('Campaign saved as template', ['campaign_id' => $campaign->id, 'template_id' => $template->id, 'user_id' => $user->id]);

        return $template;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function brief(?CampaignBrief $brief, string $clientName): ?array
    {
        if ($brief === null || $brief->isEmpty()) {
            return null;
        }

        $skeleton = [];

        foreach ($brief->content() as $field => $value) {
            $skeleton[$field] = is_array($value)
                ? array_map(fn (string $line) => $this->generalise($line, $clientName), $value)
                : ($value === null ? null : $this->generalise((string) $value, $clientName));
        }

        return $skeleton;
    }

    private function generalise(string $text, string $clientName): string
    {
        return $clientName === '' ? $text : Str::replace($clientName, '{client_name}', $text, caseSensitive: false);
    }
}
