<?php

namespace App\Enums;

enum Platform: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
        };
    }

    /**
     * Whether this platform accepts a post with no media attached (spec §12 exit criterion:
     * "Schedule blocked without media unless platform is text-only"). Interim definition — only
     * LinkedIn is treated as text-only-capable, since the exact spec §6/§9 list wasn't available
     * in this repo. See `.junie/modules/posts.md` for the open question.
     */
    public function isTextOnlyCapable(): bool
    {
        return $this === self::LinkedIn;
    }

    /**
     * Interim caption character limit used for the editor's per-target character count and the
     * §6 checklist. The exact spec §6/§9 numbers weren't available in this repo — these mirror the
     * platforms' commonly documented public limits at the time of writing.
     */
    public function captionLimit(): int
    {
        return match ($this) {
            self::Instagram => 2200,
            self::TikTok => 2200,
            self::Facebook => 63206,
            self::LinkedIn => 3000,
        };
    }

    /**
     * Interim per-platform hashtag ceiling used by App\Services\AI\AiGuardrailService (Step 2.6)
     * to flag AI caption suggestions that exceed a platform's commonly documented hashtag norms
     * — enforced as a clear warning rather than a silent edit.
     */
    public function maxHashtags(): int
    {
        return match ($this) {
            self::Instagram => 30,
            self::TikTok => 30,
            self::Facebook => 30,
            self::LinkedIn => 5,
        };
    }

    /**
     * Whether this platform's caption policy is comfortable with links inline in the caption
     * body. Used by App\Services\AI\AiGuardrailService (Step 2.6) to warn — not silently
     * strip — when an AI suggestion includes a link on a platform that generally buries/penalizes
     * in-caption links (TikTok, Instagram).
     */
    public function allowsLinksInCaption(): bool
    {
        return match ($this) {
            self::Instagram, self::TikTok => false,
            self::Facebook, self::LinkedIn => true,
        };
    }
}
