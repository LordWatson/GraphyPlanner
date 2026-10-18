<?php

namespace App\Services\Publishing;

use App\Enums\AssetType;
use App\Enums\Platform;
use App\Enums\PlatformRolloutStage;
use App\Models\Post;
use App\Models\SocialAccount;

/**
 * Step 1.7 — resolves which rollout stage a given publish target belongs to, and whether that
 * stage is currently enabled per `config('publishing.rollout')`. `UploadPostAdapter` consults
 * this before calling the vendor at all, so a disabled platform never reaches Upload-Post
 * regardless of any other checklist/gating already passed.
 */
class PlatformRolloutGate
{
    /**
     * Instagram feed vs. Reels can't be told apart from `SocialAccount::platform` alone (both
     * are `Platform::Instagram`) — it depends on the post's first attached asset, mirroring the
     * same `media_type` inference `UploadPostAdapter::applyInstagramFields()` already uses
     * (video → Reels, image/no-video → feed).
     */
    public function stageFor(Post $post, SocialAccount $account): PlatformRolloutStage
    {
        return match ($account->platform) {
            Platform::Instagram => $post->assets->first()?->type === AssetType::Video
                ? PlatformRolloutStage::InstagramReels
                : PlatformRolloutStage::InstagramFeed,
            Platform::TikTok => PlatformRolloutStage::TikTok,
            Platform::Facebook => PlatformRolloutStage::Facebook,
            Platform::LinkedIn => PlatformRolloutStage::LinkedIn,
        };
    }

    public function isEnabled(Post $post, SocialAccount $account): bool
    {
        return $this->stageEnabled($this->stageFor($post, $account));
    }

    public function stageEnabled(PlatformRolloutStage $stage): bool
    {
        return (bool) config("publishing.rollout.{$stage->value}", false);
    }

    /**
     * Campaigns enhancement C-3.3: whether the org can publish to a platform at all (any of its
     * stages enabled), so the AI planner never suggests work for a platform that can't go out.
     */
    public function platformEnabled(Platform $platform): bool
    {
        return match ($platform) {
            Platform::Instagram => $this->stageEnabled(PlatformRolloutStage::InstagramFeed)
                || $this->stageEnabled(PlatformRolloutStage::InstagramReels),
            Platform::TikTok => $this->stageEnabled(PlatformRolloutStage::TikTok),
            Platform::Facebook => $this->stageEnabled(PlatformRolloutStage::Facebook),
            Platform::LinkedIn => $this->stageEnabled(PlatformRolloutStage::LinkedIn),
        };
    }
}
