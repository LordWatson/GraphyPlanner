<?php

namespace App\Actions\Campaigns;

use App\Contracts\CampaignAiProvider;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Services\Campaigns\CampaignAiUsage;
use App\Support\AI\Campaigns\AiCampaignBriefDraft;
use Illuminate\Support\Facades\Log;

/**
 * Step C-2.2 — "Draft with AI" for the brief. Returns a suggestion only: nothing is saved until a
 * person accepts sections in the editor and saves (plan §1.3.4). Every field is PII-scrubbed and
 * checked against the Brand Brain's banned phrases; hits are flagged per field, never silently
 * edited. One usage row per call.
 */
class DraftCampaignBriefAction
{
    public function __construct(
        private readonly CampaignAiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly CampaignAiUsage $usage,
    ) {}

    public function __invoke(Campaign $campaign, User $user): AiCampaignBriefDraft
    {
        $started = microtime(true);
        $draft = $this->ai->draftCampaignBrief($campaign);
        $brandBrain = $campaign->client->brandBrain;

        $fields = [];
        $flags = [];

        foreach ($draft->fields as $field => $value) {
            $fields[$field] = is_array($value)
                ? array_map(fn (string $line) => $this->guardrail->scrub($line), $value)
                : ($value === null ? null : $this->guardrail->scrub($value));

            $hits = $this->guardrail->bannedPhrasesIn($brandBrain, is_array($value) ? implode("\n", $value) : (string) $value);

            if ($hits !== []) {
                $flags[$field] = $hits;
            }
        }

        $result = (new AiCampaignBriefDraft($fields))->withFlags($flags);

        $this->usage->record($campaign, $user, $this->ai, 'draftCampaignBrief', ! $result->isEmpty(), $started);

        Log::info('AI campaign brief drafted', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'user_id' => $user->id,
            'empty' => $result->isEmpty(),
            'flagged_fields' => array_keys($flags),
        ]);

        return $result;
    }
}
