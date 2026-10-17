<?php

namespace App\Actions\AI\Images;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\ClientContextBuilder;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step 16.5: the art-direction brief for one image set ("brief once, render many"). Asks the
 * AiProvider to draft it, runs it through AiGuardrailService::filterImageBrief(), and caches the
 * result by `brief_hash` (idea + brand fingerprint + art-direction overrides) so regenerating a
 * set with the same inputs costs zero DeepSeek tokens.
 */
class DraftImageBriefAction
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly AiGuardrailService $guardrail,
        private readonly ClientContextBuilder $context,
    ) {}

    /**
     * @return array{brief: AiImageBrief, hash: string, cached: bool}
     */
    public function __invoke(Client $client, ImageBriefInput $input): array
    {
        $hash = $this->hash($client, $input);
        $key = "ai:image-brief:{$client->id}:{$hash}";
        $logContext = ['org_id' => $client->org_id, 'client_id' => $client->id, 'brief_hash' => $hash];

        $cached = Cache::get($key);

        if (is_array($cached) && is_string($cached['subject'] ?? null)) {
            Log::info('AI image brief served from cache', $logContext);

            return ['brief' => AiImageBrief::fromArray($cached), 'hash' => $hash, 'cached' => true];
        }

        $startedAt = microtime(true);
        $brief = $this->ai->draftImageBrief($client, $input);
        $brief = $this->guardrail->filterImageBrief($client->brandBrain, $brief, $logContext);

        Cache::put($key, $brief->toArray(), now()->addHours((int) config('ai.images.brief_cache_ttl_hours', 24)));

        Log::info('AI image brief drafted', [
            ...$logContext,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'reference_count' => count($brief->referenceAssetIds),
            'has_headline' => $brief->textOverlay !== null,
        ]);

        return ['brief' => $brief, 'hash' => $hash, 'cached' => false];
    }

    public function hash(Client $client, ImageBriefInput $input): string
    {
        return hash('sha256', json_encode([
            'input' => $input->toArray(),
            'brand' => $this->context->fingerprint($client),
        ], JSON_THROW_ON_ERROR));
    }
}
