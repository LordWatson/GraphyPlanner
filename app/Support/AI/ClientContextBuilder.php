<?php

namespace App\Support\AI;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;

/**
 * Assembles the "company context" every AI request is grounded in (Step 2.10): the client's
 * profile (name, website, industry, markets, language), its full Brand Brain briefing, and its
 * planning/active campaigns — built once here so the trends/ideas/captions prompts never
 * duplicate (or drift on) what context the model sees. The rendered text is passed through
 * AiGuardrailService::scrub() so PII/secrets typed into a brand brain never reach the vendor.
 */
class ClientContextBuilder
{
    public function __construct(
        private readonly AiGuardrailService $guardrail = new AiGuardrailService,
    ) {}

    /**
     * Render the client's company context as a markdown briefing for a system prompt.
     */
    public function build(Client $client): string
    {
        $sections = [
            $this->profileSection($client),
            $this->brandBrainSection($client),
            $this->campaignsSection($client),
        ];

        return $this->guardrail->scrub(implode("\n\n", $sections));
    }

    /**
     * Stable hash of the context build() would produce, so callers that cache AI results
     * (e.g. DiscoverTrendsAction) invalidate as soon as the brand brain/campaigns change.
     */
    public function fingerprint(Client $client): string
    {
        // Visual references (Step 16.4) aren't in the text context, but they change the brand's
        // look, so cached image briefs must invalidate when they do.
        $references = $client->brandBrain?->referenceAssets()
            ->get(['asset_id', 'role', 'position'])
            ->map(fn ($reference) => $reference->asset_id.':'.$reference->role->value.':'.$reference->position)
            ->implode(',') ?? '';

        return md5($this->build($client).'|refs:'.$references);
    }

    private function profileSection(Client $client): string
    {
        $lines = [
            '# Company profile',
            '- Name: '.$client->name,
        ];

        if (filled($client->legal_name) && $client->legal_name !== $client->name) {
            $lines[] = '- Legal name: '.$client->legal_name;
        }

        $lines[] = '- Industry: '.($client->industry ?: 'Unknown');

        if (filled($client->website)) {
            $lines[] = '- Website: '.$client->website;
        }

        if (! empty($client->countries)) {
            $lines[] = '- Markets/countries: '.implode(', ', $client->countries);
        }

        if (filled($client->default_language)) {
            $lines[] = '- Primary content language: '.$client->default_language;
        }

        if (! empty($client->tags)) {
            $lines[] = '- Tags: '.implode(', ', $client->tags);
        }

        return implode("\n", $lines);
    }

    private function brandBrainSection(Client $client): string
    {
        $brandBrain = $client->brandBrain;

        if ($brandBrain === null) {
            return "# Brand Brain\nNo brand brain has been filled in for this client yet — stay generic but appropriate for the industry.";
        }

        return $brandBrain->toGrokContext()['markdown'];
    }

    private function campaignsSection(Client $client): string
    {
        $campaigns = $client->campaigns()
            ->whereIn('status', [CampaignStatus::Active, CampaignStatus::Planning])
            ->orderBy('start_date')
            ->get();

        if ($campaigns->isEmpty()) {
            return "# Current campaigns\n(none)";
        }

        $lines = ['# Current campaigns'];

        foreach ($campaigns as $campaign) {
            $lines[] = '- '.$this->describeCampaign($campaign);
        }

        return implode("\n", $lines);
    }

    /**
     * One-line campaign summary, shared with DeepSeekProvider's per-post caption prompt.
     */
    public function describeCampaign(Campaign $campaign): string
    {
        $parts = [$campaign->name.' ('.$campaign->status->label().')'];

        if ($campaign->start_date !== null || $campaign->end_date !== null) {
            $parts[] = ($campaign->start_date?->toDateString() ?? '?').' → '.($campaign->end_date?->toDateString() ?? 'ongoing');
        }

        if (filled($campaign->goal)) {
            $parts[] = 'goal: '.$campaign->goal;
        }

        return implode(' — ', $parts);
    }
}
