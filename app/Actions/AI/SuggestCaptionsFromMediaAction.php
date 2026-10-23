<?php

namespace App\Actions\AI;

use App\Contracts\AssetStorage;
use App\Contracts\VisionProvider;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Client;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiTextUsage;
use App\Services\AI\GeminiVisionProvider;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\VisionImage;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.2 — "caption from a photo". Given one of the brand's image assets, load its bytes, hand
 * them to the {@see VisionProvider} for on-brand caption options, guardrail the result and record
 * usage. The brand's connected platforms steer the hashtags.
 *
 * Nothing is persisted — the suggestions are for the user to pick from, exactly like the text
 * caption helper. Degrades to empty suggestions (never an error) when: the asset is not an image,
 * its bytes are missing or too large, AI is off, or the provider fails — the plan's rule that the
 * user is always left with a usable (if empty) caption box.
 */
class SuggestCaptionsFromMediaAction
{
    public function __construct(
        private readonly VisionProvider $vision,
        private readonly AssetStorage $storage,
        private readonly AiGuardrailService $guardrail,
        private readonly AiTextUsage $usage,
    ) {}

    public function __invoke(Client $brand, Asset $asset): AiCaptionSuggestions
    {
        $context = ['org_id' => $brand->org_id, 'client_id' => $brand->id, 'asset_id' => $asset->id];

        if ($asset->type !== AssetType::Image || $asset->disk === null || $asset->path === null) {
            Log::warning('Caption-from-photo skipped: asset is not a usable image', $context);

            return new AiCaptionSuggestions;
        }

        $maxBytes = max(1, (int) config('ai.vision.max_image_bytes', 8 * 1024 * 1024));

        if ($asset->size !== null && $asset->size > $maxBytes) {
            Log::warning('Caption-from-photo skipped: image too large', [...$context, 'size' => $asset->size]);

            return new AiCaptionSuggestions;
        }

        $bytes = $this->storage->contents($asset->disk, $asset->path);

        if ($bytes === null || $bytes === '') {
            Log::warning('Caption-from-photo skipped: asset bytes missing', $context);

            return new AiCaptionSuggestions;
        }

        $image = new VisionImage($asset->mime_type ?? 'image/jpeg', $bytes);

        $startedAt = microtime(true);
        $suggestions = $this->vision->captionsFromImage($brand, $image, $this->platforms($brand));

        $gemini = $this->vision instanceof GeminiVisionProvider;
        $this->usage->record(
            $brand,
            'captionsFromImage',
            $suggestions->suggestions !== [],
            $startedAt,
            $gemini ? 'gemini' : 'null',
            $gemini ? (string) config('ai.vision.model', 'gemini') : 'none',
        );

        return $this->guardrail->filterCaptionSuggestions($brand->brandBrain, $suggestions, $context);
    }

    /**
     * The distinct platforms the brand has connected, so hashtags suit where the photo will go.
     *
     * @return list<string>
     */
    private function platforms(Client $brand): array
    {
        return array_values(array_unique($brand->socialAccounts()
            ->get(['platform'])
            ->map(fn ($account) => $account->platform->value)
            ->all()));
    }
}
