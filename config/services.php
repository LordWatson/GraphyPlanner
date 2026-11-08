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
        // The endpoint secret (`whsec_…`) for the delivery-event webhook that suppresses bounced
        // and complained addresses. `ResendWebhookController` refuses every delivery until it is
        // set — the signature is the only authorisation on that endpoint, and a forged one would
        // add somebody else's address to a platform-wide do-not-send list.
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
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

    // Upload-Post (Phase 1 publish vendor, spec §7/§7.1). In "byo" mode (today's agency default)
    // the per-request API key comes from each organization's own `upload_post_key` (Step 0.15),
    // and the webhook signing secret (Step 1.5) from its `upload_post_webhook_secret` — Upload-Post
    // issues one secret per account. In "managed" mode (Step B-5.1, business workspaces) there is
    // no per-org key: Alima authenticates with ONE platform key below, and each org is isolated as
    // its own Upload-Post profile (`organizations.upload_post_profile`). The platform's single
    // webhook secret signs every managed org's deliveries. All three are resolved through
    // `App\Contracts\PublishCredentials`, never read directly, and are never logged.
    'upload_post' => [
        // Step M-2.5: the notifications (webhook registration) endpoint lives on the app host.
        'app_base_url' => env('UPLOAD_POST_APP_BASE_URL', 'https://app.upload-post.com/api'),
        'base_url' => env('UPLOAD_POST_BASE_URL', 'https://api.upload-post.com/api'),
        // Step B-5.1: managed-mode platform credentials (shared by every business workspace).
        'platform_key' => env('UPLOAD_POST_PLATFORM_KEY'),
        'platform_webhook_secret' => env('UPLOAD_POST_PLATFORM_WEBHOOK_SECRET'),
    ],

    // Meta Graph API direct integration (Step 1.9), used only for Instagram audio search
    // (`GET /ig_audio`, spec https://developers.facebook.com/documentation/instagram-platform/
    // content-publishing/audio-api) — Upload-Post has no Instagram sound-library endpoint
    // (confirmed in Step 1.8.2). This is a separate Facebook App (Facebook Login for Business,
    // `instagram_basic` + `instagram_content_publish`) from Upload-Post; the client secret is
    // never logged, mirroring the `upload_post_key` handling convention.
    // WhatsApp Business Cloud API (Step W-0.1 of `.junie/plans/whatsapp-notifications.md`). A
    // separate Meta app from `facebook` above: Cloud API messaging has its own business account,
    // its own sending number and its own permanent system-user token. Today one platform number
    // sends for every organization; Step W-4.1 gives an organization its own number and token,
    // stored like `upload_post_key`. All of it is resolved through
    // `App\Services\WhatsApp\WhatsAppCredentials`, never read in a caller, and never logged.
    // `app_secret`/`verify_token` belong to the inbound webhook (Step W-3.1), not to sending.
    // `WHATSAPP_CLIENT=null` binds the no-op client and turns WhatsApp off entirely; leaving the
    // token unset is already safe (every send reports not-configured without calling anyone).
    'whatsapp' => [
        'client' => env('WHATSAPP_CLIENT', 'cloud_api'),
        'base_url' => env('WHATSAPP_BASE_URL') ?: 'https://graph.facebook.com',
        'graph_version' => env('WHATSAPP_GRAPH_VERSION') ?: 'v21.0',
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'graph_base_url' => env('FACEBOOK_GRAPH_BASE_URL') ?: 'https://graph.facebook.com',
        'graph_version' => env('FACEBOOK_GRAPH_VERSION') ?: 'v20.0',
    ],

];
