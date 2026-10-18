<?php

namespace App\Actions\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\CampaignKpiService;
use App\Services\Campaigns\CampaignAiUsage;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use Illuminate\Support\Facades\Log;

/**
 * Step C-7.3 — "Draft with AI" for the wrap report: summary, highlights, learnings and next steps
 * from the live KPI results, the brief's success measures and the top posts. Suggestion only,
 * accepted per section; guardrail hits flagged; one usage row.
 */
class DraftCampaignWrapAction
{
    public function __construct(
        private readonly CampaignAiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly CampaignAiUsage $usage,
        private readonly CampaignKpiService $kpis,
        private readonly GetCampaignResultsAction $results,
    ) {}

    public function __invoke(Campaign $campaign, User $user): AiCampaignWrapDraft
    {
        $started = microtime(true);

        $draft = $this->ai->draftCampaignWrap($campaign, [
            'kpis' => $this->kpis->results($campaign),
            'top_posts' => $this->results->topPosts($campaign),
            'published' => $campaign->posts()->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])->count(),
            'success_measures' => $campaign->brief?->success_measures,
        ]);

        $brandBrain = $campaign->client->brandBrain;
        $scrub = fn (?string $text) => $text === null ? null : $this->guardrail->scrub($text);
        $draft = new AiCampaignWrapDraft(
            summary: $scrub($draft->summary),
            highlights: array_map(fn (string $line) => $this->guardrail->scrub($line), $draft->highlights),
            learnings: $scrub($draft->learnings),
            nextSteps: $scrub($draft->nextSteps),
        );

        $flags = [];

        foreach (['summary' => $draft->summary, 'highlights' => implode("\n", $draft->highlights), 'learnings' => $draft->learnings, 'next_steps' => $draft->nextSteps] as $field => $text) {
            $hits = $this->guardrail->bannedPhrasesIn($brandBrain, (string) $text);

            if ($hits !== []) {
                $flags[$field] = $hits;
            }
        }

        $empty = $draft->summary === null && $draft->highlights === [];
        $this->usage->record($campaign, $user, $this->ai, 'draftCampaignWrap', ! $empty, $started);

        Log::info('AI campaign wrap drafted', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'user_id' => $user->id,
            'empty' => $empty,
            'flagged_fields' => array_keys($flags),
        ]);

        return $draft->withFlags($flags);
    }
}
