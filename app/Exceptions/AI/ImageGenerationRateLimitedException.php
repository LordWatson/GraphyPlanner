<?php

namespace App\Exceptions\AI;

/**
 * The org hit `config('ai.images.sets_per_minute')` (Step 16.7).
 */
class ImageGenerationRateLimitedException extends ImageStudioException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct("That's a lot of images at once. Try again in {$retryAfterSeconds} seconds.");
    }
}
