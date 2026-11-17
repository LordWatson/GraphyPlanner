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
        // Step I-3.4 / M-2.6: the MVP's added networks, in its order. Each has a faked-vendor
        // publish test against the documented Upload-Post fields; switch one off via env if the
        // live sandbox disagrees. Bluesky is deferred (see publishing.md, Capabilities).
        PlatformRolloutStage::YouTubeShorts->value => env('PUBLISH_ROLLOUT_YOUTUBE_SHORTS', true),
        PlatformRolloutStage::GoogleBusiness->value => env('PUBLISH_ROLLOUT_GOOGLE_BUSINESS', true),
        PlatformRolloutStage::Threads->value => env('PUBLISH_ROLLOUT_THREADS', true),
        PlatformRolloutStage::Pinterest->value => env('PUBLISH_ROLLOUT_PINTEREST', true),
        PlatformRolloutStage::X->value => env('PUBLISH_ROLLOUT_X', true),
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

    /*
    |--------------------------------------------------------------------------
    | Unsigned publish webhooks (local development only)
    |--------------------------------------------------------------------------
    |
    | `UploadPostWebhookController` fails closed: the signature is the whole authorisation on that
    | public endpoint, so a delivery it cannot verify — including one for an organization with no
    | webhook secret stored yet — is refused with a 401 and an ops alert.
    |
    | Setting this to true accepts unsigned deliveries, which lets you replay a captured payload
    | against a local machine with `curl`. It must be false in every deployed environment:
    | with it on, anyone who learns a `job_id` can mark a post published (with a URL the client
    | will see) or failed. `SecurityHardeningTest` asserts the default stays false.
    |
    */
    'webhook_allow_unsigned' => (bool) env('PUBLISH_WEBHOOK_ALLOW_UNSIGNED', false),

    /*
    |--------------------------------------------------------------------------
    | Stories with extras: the assisted lane (Phase S)
    |--------------------------------------------------------------------------
    |
    | Instagram's publishing API cannot add a link, a poll, a mention or any other sticker to a
    | Story, so a Story channel that carries extras is held back from the vendor and handed to a
    | person to post from the Instagram app (`.junie/plans/story-extras.md`).
    |
    | `enabled` is the kill switch (Step S-1.1). Off: the editor hides Story extras, a save ignores
    | any that are submitted and `post_targets.handoff_required` is always written false. Rows that
    | are already flagged are left alone, so a Story that is mid-handoff still finishes.
    |
    | `platforms` is the list the lane is offered on. The data model is keyed on the `story` format,
    | so Facebook (Step S-6.3) is a config change, not a migration.
    |
    */
    'story_handoff' => [
        'enabled' => (bool) env('STORY_HANDOFF_ENABLED', false),
        'platforms' => ['instagram'],
        // Due time → the Story counts as missed.
        'window_minutes' => (int) env('STORY_HANDOFF_WINDOW_MINUTES', 120),
        // After the first notification, one nudge if nobody has shared it yet.
        'nudge_after_minutes' => (int) env('STORY_HANDOFF_NUDGE_MINUTES', 15),
        // How long the handoff link opens after the due time. A Story lives 24h; a little slack.
        'token_ttl_hours' => (int) env('STORY_HANDOFF_TOKEN_TTL_HOURS', 30),
        // A sweep running later than this still notifies, with "This was due at 10:00".
        'late_grace_minutes' => 10,
        // Above this size the handoff page skips the in-memory share and leads with Save media:
        // a phone browser holding a 100 MB blob for the share sheet is the likeliest thing to fail.
        'max_share_mb' => (int) env('STORY_HANDOFF_MAX_SHARE_MB', 50),
    ],

];
