<?php

namespace App\Services\Publishing;

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
     * are `Platform::Instagram`) — a post that sends a video goes out as a Reel (or a video
     * Story), anything else through the feed stage. Every other platform has one stage.
     */
    public function stageFor(Post $post, SocialAccount $account): PlatformRolloutStage
    {
        if ($account->platform === Platform::Instagram) {
            return in_array('video', $post->mediaKinds(), true)
                ? PlatformRolloutStage::InstagramReels
                : PlatformRolloutStage::InstagramFeed;
        }

        return PlatformRolloutStage::forPlatform($account->platform)[0];
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
        return collect(PlatformRolloutStage::forPlatform($platform))
            ->contains(fn (PlatformRolloutStage $stage) => $this->stageEnabled($stage));
    }
}
