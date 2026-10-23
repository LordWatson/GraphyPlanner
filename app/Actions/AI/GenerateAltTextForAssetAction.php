<?php

namespace App\Actions\AI;

use App\Models\Asset;
use App\Models\Client;
use App\Services\AI\AiUsageGuard;

/**
 * Step M-7.3 (dev plan I-9.4) — "generate alt text from the image" for the editor's media panel.
 * Reuses the vision caption path ({@see SuggestCaptionsFromMediaAction}, which validates the image,
 * loads its bytes, guardrails and meters the vendor call) and keeps just the alt-text line. Returns
 * null when nothing usable came back (vision off, not an image, provider failure) so the editor can
 * say so rather than blanking the field. Budget-gated like every agency text/AI call.
 */
class GenerateAltTextForAssetAction
{
    public function __construct(
        private readonly SuggestCaptionsFromMediaAction $captions,
        private readonly AiUsageGuard $budget = new AiUsageGuard,
    ) {}

    public function __invoke(Client $client, Asset $asset): ?string
    {
        $this->budget->ensureWithinBudget($client->organization);

        foreach (($this->captions)($client, $asset)->suggestions as $suggestion) {
            if ($suggestion->altText !== null && trim($suggestion->altText) !== '') {
                return trim($suggestion->altText);
            }
        }

        return null;
    }
}
