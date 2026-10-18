<?php

namespace App\Actions\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\Campaigns\CampaignAiUsage;
use App\Services\Publishing\PlatformRolloutGate;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;
use App\Support\Campaigns\CampaignClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.3 — the AI campaign planner. Suggests deliverables from the brief, phases, platforms,
 * dates and Brand Brain pillars; returns them for a review drawer and writes nothing (accepting is
 * `AcceptCampaignPlanAction`).
 *
 * - Platforms are limited to the campaign's intended ones that the org can publish to (rollout
 *   gating) — a suggestion for any other platform is dropped, whatever the model says.
 * - Dates outside the campaign are dropped.
 * - Cadence: a day that already has posts or planned slots for that platform gets a warning.
 * - Banned phrases are flagged on the suggestion.
 */
class PlanCampaignAction
{
    public const MAX_SUGGESTIONS = 40;

    public function __construct(
        private readonly CampaignAiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly CampaignAiUsage $usage,
        private readonly PlatformRolloutGate $rollout,
    ) {}

    /**
     * @return list<AiDeliverableSuggestion>
     */
    public function __invoke(Campaign $campaign, User $user): array
    {
        $platforms = $this->allowedPlatforms($campaign);

        if ($platforms === []) {
            throw ValidationException::withMessages(['platforms' => 'None of this campaign’s platforms can be published to yet, so there’s nothing to plan.']);
        }

        $today = CampaignClock::today($campaign);
        $start = $campaign->start_date ? CarbonImmutable::parse($campaign->start_date->toDateString()) : $today;
        $end = $campaign->end_date ? CarbonImmutable::parse($campaign->end_date->toDateString()) : $start->addDays(27);
        $started = microtime(true);

        $raw = $this->ai->planCampaign($campaign, [
            'platforms' => $platforms,
            'phases' => array_values($campaign->phases()->get()->map(fn (CampaignPhase $phase) => [
                'id' => $phase->id,
                'name' => $phase->name,
                'starts_on' => $phase->starts_on?->toDateString(),
                'ends_on' => $phase->ends_on?->toDateString(),
            ])->all()),
            'pillars' => array_values($campaign->client->brandBrain->content_pillars ?? []),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'max' => self::MAX_SUGGESTIONS,
        ]);

        $busy = $this->busyDays($campaign, $start, $end);
        $brandBrain = $campaign->client->brandBrain;
        $suggestions = [];

        foreach ($raw as $suggestion) {
            $date = $this->parseDate($suggestion->date);

            if (! in_array($suggestion->platform, $platforms, true) || $date === null || $date->lessThan($start) || $date->greaterThan($end)) {
                continue;
            }

            $flags = $this->guardrail->bannedPhrasesIn($brandBrain, implode(' ', array_filter([$suggestion->title, $suggestion->angle])));
            $count = $busy[$suggestion->platform][$date->toDateString()] ?? 0;
            $label = Platform::from($suggestion->platform)->label();
            $warnings = $count > 0 ? ["{$label} already has {$count} ".($count === 1 ? 'post' : 'posts').' planned that day.'] : [];

            $suggestions[] = $suggestion->with($flags, $warnings);
        }

        $this->usage->record($campaign, $user, $this->ai, 'planCampaign', $suggestions !== [], $started);

        Log::info('AI campaign plan suggested', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'user_id' => $user->id,
            'received' => count($raw),
            'kept' => count($suggestions),
        ]);

        return $suggestions;
    }

    /**
     * @return list<string>
     */
    public function allowedPlatforms(Campaign $campaign): array
    {
        $intended = $campaign->platforms ?: array_map(fn (Platform $platform) => $platform->value, Platform::cases());

        return array_values(array_filter(
            $intended,
            fn (string $value) => ($platform = Platform::tryFrom($value)) !== null && $this->rollout->platformEnabled($platform),
        ));
    }

    /**
     * Posts already scheduled for the client plus slots already planned in this campaign, per
     * platform per day.
     *
     * @return array<string, array<string, int>>
     */
    private function busyDays(Campaign $campaign, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $busy = [];

        $targets = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->where('posts.client_id', $campaign->client_id)
            ->whereNull('posts.deleted_at')
            ->whereBetween('post_targets.scheduled_local_date', [$start->toDateString(), $end->toDateString()])
            ->get(['social_accounts.platform', 'post_targets.scheduled_local_date']);

        foreach ($targets as $row) {
            $day = substr((string) $row->scheduled_local_date, 0, 10);
            $busy[(string) $row->platform][$day] = ($busy[(string) $row->platform][$day] ?? 0) + 1;
        }

        $planned = $campaign->deliverables()->whereNull('post_id')->whereNotNull('planned_for')->get(['platform', 'planned_for']);

        foreach ($planned as $deliverable) {
            $day = (string) $deliverable->planned_for?->toDateString();
            $busy[$deliverable->platform->value][$day] = ($busy[$deliverable->platform->value][$day] ?? 0) + 1;
        }

        return $busy;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
    }
}
