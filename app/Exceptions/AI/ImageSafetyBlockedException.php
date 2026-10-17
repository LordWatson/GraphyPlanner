<?php

namespace App\Exceptions\AI;

use App\Enums\ImageErrorCode;

/**
 * The vendor refused the request or withheld the image on safety grounds.
 */
class ImageSafetyBlockedException extends ImageProviderException
{
    public function errorCode(): ImageErrorCode
    {
        return ImageErrorCode::SafetyBlocked;
    }
}
