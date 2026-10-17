<?php

namespace App\Exceptions\AI;

use App\Enums\ImageErrorCode;

/**
 * Thrown by App\Services\AI\Images\ImageQuotaGuard when a request would exceed the org's
 * `ai_monthly_image_quota` for the calendar month (Step 16.11).
 */
class AiImageQuotaExceededException extends ImageStudioException
{
    public function __construct()
    {
        parent::__construct(ImageErrorCode::QuotaExceeded->userMessage());
    }
}
