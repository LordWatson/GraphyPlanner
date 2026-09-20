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
    // each organization's `upload_post_key` (Step 0.15), not from an env var here.
    'upload_post' => [
        'base_url' => env('UPLOAD_POST_BASE_URL', 'https://api.upload-post.com/api'),

        // Step 1.5: shared secret used to verify the `X-Upload-Post-Signature` header on
        // `POST /webhooks/upload-post`. Left empty in local/dev until Upload-Post issues one —
        // the webhook controller logs a warning and skips verification when unset rather than
        // rejecting every request.
        'webhook_secret' => env('UPLOAD_POST_WEBHOOK_SECRET', ''),
    ],

];
