<?php

namespace App\Exceptions\AI;

use App\Enums\ImageErrorCode;

/**
 * The vendor did not answer within `config('ai.images.timeout')` seconds.
 */
class ImageProviderTimeoutException extends ImageProviderException
{
    public function errorCode(): ImageErrorCode
    {
        return ImageErrorCode::Timeout;
    }
}
