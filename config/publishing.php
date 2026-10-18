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

    /*
    |--------------------------------------------------------------------------
    | Vendor HTTP client (Step I-1.3)
    |--------------------------------------------------------------------------
    |
    | Applied to every Upload-Post / Meta Graph call through `App\Support\Publishing\VendorHttp`.
    | Reads (status, health, music search) retry on 429/5xx/connection errors with jittered
    | exponential backoff. A publish POST only retries on 429 (the vendor explicitly didn't take
    | it) — a 5xx or timeout may still have posted, and a double post is worse than a failure.
    |
    */
    'http' => [
        'timeout' => (int) env('PUBLISH_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('PUBLISH_HTTP_CONNECT_TIMEOUT', 10),
        'retries' => (int) env('PUBLISH_HTTP_RETRIES', 2),
        'retry_base_ms' => (int) env('PUBLISH_HTTP_RETRY_BASE_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery confirmation timeout (Step I-1.3)
    |--------------------------------------------------------------------------
    |
    | A target still `pending` on a `publishing` post this many hours after its last change is
    | marked failed by `PollPendingPublishStatusesAction` ("No delivery confirmation after N
    | hours"), so a post can never sit in `publishing` forever.
    |
    */
    'delivery_timeout_hours' => (int) env('PUBLISH_DELIVERY_TIMEOUT_HOURS', 24),

];
