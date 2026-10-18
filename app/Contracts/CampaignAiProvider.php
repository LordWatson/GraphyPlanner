<?php

namespace App\Contracts;

use App\Models\Campaign;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use App\Support\AI\Campaigns\AiCampaignWrapDraft;
use App\Support\AI\Campaigns\AiDeliverableSuggestion;

/**
 * Campaigns enhancement (C-2.2, C-3.3, C-7.3) — the AI's campaign work: draft a brief, plan
 * deliverables, draft the wrap commentary. Kept apart from `AiProvider` (interface segregation)
 * so the many `AiProvider` test doubles don't all grow three methods; `DeepSeekProvider` and
 * `NullAiProvider` implement both, and the binding follows the same AI-enabled + key rule.
 *
 * Implementations never persist anything and never throw on a vendor failure: they return an
 * empty suggestion instead. Raw prompts are never logged.
 */
interface CampaignAiProvider
{
    public function draftCampaignBrief(Campaign $campaign): AiCampaignBriefDraft;

    /**
     * @param  array{platforms: list<string>, phases: list<array{id: int, name: string, starts_on: string|null, ends_on: string|null}>, pillars: list<string>, start: string, end: string, max: int}  $input
     * @return list<AiDeliverableSuggestion>
     */
    public function planCampaign(Campaign $campaign, array $input): array;

    /**
     * @param  array{kpis: list<array<string, mixed>>, top_posts: list<array<string, mixed>>, published: int, success_measures: string|null}  $facts
     */
    public function draftCampaignWrap(Campaign $campaign, array $facts): AiCampaignWrapDraft;
}
