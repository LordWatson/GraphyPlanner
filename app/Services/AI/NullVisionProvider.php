<?php

namespace App\Services\AI;

use App\Contracts\VisionProvider;
use App\Models\Client;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\VisionImage;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.2 — the safe default {@see VisionProvider}, resolved when AI is off or no Gemini key is
 * set. Never calls a vendor; always returns empty suggestions (an empty caption box), so the UI
 * works end to end without vision configured.
 */
class NullVisionProvider implements VisionProvider
{
    public function captionsFromImage(Client $client, VisionImage $image, array $platforms): AiCaptionSuggestions
    {
        Log::info('Vision captions answered by NullVisionProvider (no vendor call made)', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'ai_enabled' => (bool) config('ai.enabled', false),
        ]);

        return new AiCaptionSuggestions;
    }
}
