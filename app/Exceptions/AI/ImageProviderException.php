<?php

namespace App\Exceptions\AI;

use App\Enums\ImageErrorCode;
use RuntimeException;

/**
 * Base failure thrown by an App\Contracts\ImageProvider (Step 16.6). The message is for logs only
 * (already free of prompts/keys); the studio only ever shows errorCode()->userMessage().
 */
class ImageProviderException extends RuntimeException
{
    public function errorCode(): ImageErrorCode
    {
        return ImageErrorCode::ProviderError;
    }
}
