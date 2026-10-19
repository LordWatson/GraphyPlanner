<?php

namespace App\Enums;

use App\Services\Publishing\PlatformCapabilities;
use App\Support\Publishing\PlatformSpec;

/**
 * The networks a social account can live on. Every rule about a platform (limits, formats, media,
 * link policy, vendor field names) lives in `config/platforms.php`, read through
 * `App\Services\Publishing\PlatformCapabilities` (Step I-3.3 / M-2.1). The helpers below only
 * delegate to it, so existing callers keep working and never hold a rule of their own.
 */
enum Platform: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    // Step I-3.4 / M-2.6 — each behind its own rollout flag (`config/publishing.php`).
    case YouTube = 'youtube';
    case GoogleBusiness = 'google_business';
    case Threads = 'threads';
    case Pinterest = 'pinterest';
    case X = 'x';

    public function spec(): PlatformSpec
    {
        return app(PlatformCapabilities::class)->for($this);
    }

    public function label(): string
    {
        return $this->spec()->label;
    }

    /**
     * Whether this platform accepts a post with no media attached (spec §12 exit criterion:
     * "Schedule blocked without media unless platform is text-only").
     */
    public function isTextOnlyCapable(): bool
    {
        return $this->spec()->textOnly;
    }

    /**
     * Step I-2.2: the stored values of every text-only-capable platform, for SQL filters (Home's
     * "missing media" bucket) that must agree with `isTextOnlyCapable()` and the §6 checklist.
     *
     * @return list<string>
     */
    public static function textOnlyCapableValues(): array
    {
        return array_values(array_map(
            fn (self $platform) => $platform->value,
            array_filter(self::cases(), fn (self $platform) => $platform->isTextOnlyCapable()),
        ));
    }

    /**
     * Caption character limit, counted on the caption as it publishes (hashtags included).
     */
    public function captionLimit(): int
    {
        return $this->spec()->captionLimit;
    }

    /**
     * The platform's hashtag norm, used by the checklist and the AI guardrails (Step 2.6) as a
     * clear warning rather than a silent edit.
     */
    public function maxHashtags(): int
    {
        return $this->spec()->maxHashtags;
    }

    /**
     * Whether a link in the caption body is clickable / welcome here. Platforms that bury
     * in-caption links (TikTok, Instagram) get a warning, never a silent strip.
     */
    public function allowsLinksInCaption(): bool
    {
        return $this->spec()->linksInCaption;
    }
}
