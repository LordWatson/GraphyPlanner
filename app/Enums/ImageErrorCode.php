<?php

namespace App\Enums;

/**
 * Why a variant failed. Each maps to one friendly line in the studio, never a vendor message.
 */
enum ImageErrorCode: string
{
    case SafetyBlocked = 'safety_blocked';
    case Timeout = 'timeout';
    case ProviderError = 'provider_error';
    case QuotaExceeded = 'quota_exceeded';

    public function userMessage(): string
    {
        return match ($this) {
            self::SafetyBlocked => "We couldn't make that one. Try softer wording.",
            self::Timeout, self::ProviderError => 'Image drafting is taking a break. Try again in a minute.',
            self::QuotaExceeded => "You've used this month's images. Your owner can raise the limit.",
        };
    }
}
