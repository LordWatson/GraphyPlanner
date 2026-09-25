<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Upload-Post (Phase 1 publish vendor, spec §7/§7.1). The per-request API key comes from
    // each organization's `upload_post_key` (Step 0.15), not from an env var here. Likewise,
    // the webhook signing secret (Step 1.5) comes from each organization's
    // `upload_post_webhook_secret` — Upload-Post issues one secret per account, not a single
    // app-wide one, so there's no env var/config key for it here.
    'upload_post' => [
        'base_url' => env('UPLOAD_POST_BASE_URL', 'https://api.upload-post.com/api'),
    ],

    // Meta Graph API direct integration (Step 1.9), used only for Instagram audio search
    // (`GET /ig_audio`, spec https://developers.facebook.com/documentation/instagram-platform/
    // content-publishing/audio-api) — Upload-Post has no Instagram sound-library endpoint
    // (confirmed in Step 1.8.2). This is a separate Facebook App (Facebook Login for Business,
    // `instagram_basic` + `instagram_content_publish`) from Upload-Post; the client secret is
    // never logged, mirroring the `upload_post_key` handling convention.
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'graph_base_url' => env('FACEBOOK_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v20.0'),
    ],

];
