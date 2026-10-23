<?php

namespace App\Contracts;

use App\Models\Client;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\VisionImage;

/**
 * Step B-6.2 — "caption from a photo": a vision-capable model that looks at an image and writes
 * on-brand caption options (plus hashtags/alt text). Its own contract (bound in a Service Provider
 * like {@see LocalIdeaProvider}) because the vendor here is Gemini — the project's image model — not
 * the DeepSeek text provider; keeping it separate means neither path disturbs the other.
 *
 * Implementations never throw: an unsupported image, a disabled provider or a vendor failure yields
 * empty suggestions, so the caller is left with an empty caption box rather than a blocked editor.
 */
interface VisionProvider
{
    /**
     * Suggest captions for the given image, grounded in the brand's voice and respecting its hashtag
     * policy, with hashtags appropriate to the given platforms.
     *
     * @param  array<int, string>  $platforms  the platforms the captions are for (influences hashtags)
     */
    public function captionsFromImage(Client $client, VisionImage $image, array $platforms): AiCaptionSuggestions;
}
