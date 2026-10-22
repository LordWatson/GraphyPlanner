<?php

namespace App\Actions\AI;

use App\Contracts\AiProvider;
use App\Contracts\WebsiteSummarizer;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\BrandProfileInput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Step B-2.4 — the brand-profile AI quick-start behind the onboarding wizard's "Your voice" step.
 *
 * It turns the few facts the owner typed (name, one-liner, location, optional website/handle bio)
 * into a draft Brand Brain for them to accept or edit. Flow:
 *  1. if a website URL was given, fetch readable text through the SSRF-guarded `WebsiteSummarizer`
 *     (which returns null on any unsafe/failed fetch — never throws);
 *  2. ask the `AiProvider` to draft voice/audience/offer/content pillars;
 *  3. record the call in `ai_usage_logs` (provider, model, success, latency) for metering (B-9.2);
 *  4. return the draft — **nothing is persisted**. Acceptance goes through `UpdateBrandBrainAction`.
 *
 * A disabled or failing provider yields `AiBrandProfileDraft::empty()`, which the wizard shows as a
 * friendly editable blank form rather than a blocked step.
 */
class DraftBrandProfileAction
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly WebsiteSummarizer $summarizer,
    ) {}

    /**
     * @param  array{name?: string|null, one_liner?: string|null, location?: string|null, website?: string|null, bio?: string|null}  $data
     */
    public function __invoke(Client $client, array $data): AiBrandProfileDraft
    {
        $name = trim((string) ($data['name'] ?? '')) ?: $client->name;
        $websiteUrl = trim((string) ($data['website'] ?? ''));

        $websiteText = $websiteUrl !== '' ? $this->summarizer->summarize($websiteUrl) : null;

        $input = new BrandProfileInput(
            name: $name,
            oneLiner: $this->clean($data['one_liner'] ?? null),
            location: $this->clean($data['location'] ?? null),
            websiteText: $websiteText,
            bioText: $this->clean($data['bio'] ?? null),
        );

        $startedAt = microtime(true);
        $draft = $this->provider->draftBrandProfile($input);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->recordUsage($client, $draft, $durationMs);

        Log::info('Brand profile draft generated', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'succeeded' => $draft->succeeded,
            'website_summarized' => $websiteText !== null,
            'duration_ms' => $durationMs,
        ]);

        return $draft;
    }

    private function recordUsage(Client $client, AiBrandProfileDraft $draft, int $durationMs): void
    {
        AiUsageLog::create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'user_id' => Auth::id(),
            'provider' => $draft->provider ?? 'null',
            'model' => $draft->model ?? 'none',
            'method' => 'draftBrandProfile',
            'succeeded' => $draft->succeeded,
            'cost_estimate' => 0,
            'duration_ms' => $durationMs,
        ]);
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
