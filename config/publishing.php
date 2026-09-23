<?php

use App\Enums\PlatformRolloutStage;

return [

    /*
    |--------------------------------------------------------------------------
    | Platform rollout (Step 1.7)
    |--------------------------------------------------------------------------
    |
    | Each publish platform is enabled independently, in the plan's literal order — Instagram
    | feed, then Reels, then TikTok, then Facebook, then LinkedIn — so a regression on a
    | newly-enabled platform can never affect one that's already confirmed working. Every stage
    | below defaults to enabled because each already has a passing publish test against faked
    | sandbox-shaped data (`UploadPostAdapterTest`) from Steps 1.2-1.6 — the flags exist so a
    | *future* platform/adapter change can be rolled back independently per stage (e.g. via env)
    | without touching the others, per Step 1.7. See `App\Services\Publishing\PlatformRolloutGate`
    | and `.junie/modules/publishing.md`.
    |
    */
    'rollout' => [
        PlatformRolloutStage::InstagramFeed->value => env('PUBLISH_ROLLOUT_INSTAGRAM_FEED', true),
        PlatformRolloutStage::InstagramReels->value => env('PUBLISH_ROLLOUT_INSTAGRAM_REELS', true),
        PlatformRolloutStage::TikTok->value => env('PUBLISH_ROLLOUT_TIKTOK', true),
        PlatformRolloutStage::Facebook->value => env('PUBLISH_ROLLOUT_FACEBOOK', true),
        PlatformRolloutStage::LinkedIn->value => env('PUBLISH_ROLLOUT_LINKEDIN', true),
    ],

];
