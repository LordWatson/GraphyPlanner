<?php

namespace App\Enums;

/**
 * Step 1.7 — publish rollout order (spec plan §1.7): Instagram feed → Reels → TikTok →
 * Facebook → LinkedIn, each gated behind its own flag until confirmed working against sandbox
 * data. Instagram feed/Reels aren't separate `Platform` enum values (both are the `instagram`
 * platform, distinguished only by the post's derived `media_type`), so this enum exists
 * specifically to give each of the five rollout milestones its own togglable stage — see
 * `App\Services\Publishing\PlatformRolloutGate::stageFor()` for how a `SocialAccount`/`Post`
 * pair resolves to one of these.
 */
enum PlatformRolloutStage: string
{
    case InstagramFeed = 'instagram_feed';
    case InstagramReels = 'instagram_reels';
    case TikTok = 'tiktok';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::InstagramFeed => 'Instagram feed',
            self::InstagramReels => 'Instagram Reels',
            self::TikTok => 'TikTok',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
        };
    }

    /**
     * The plan's literal rollout order — each stage is meant to be enabled only once the
     * previous one has a passing sandbox publish test.
     *
     * @return array<int, self>
     */
    public static function order(): array
    {
        return [self::InstagramFeed, self::InstagramReels, self::TikTok, self::Facebook, self::LinkedIn];
    }
}
