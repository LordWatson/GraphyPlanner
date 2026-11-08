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
    // WhatsApp through Twilio (`.junie/plans/whatsapp-notifications.md`). Twilio is the BSP: it
    // owns the WhatsApp Business Account, the sender registration and the template submissions.
    //
    // The values here are the *platform* fallback — a shared sandbox sender in development, or a
    // single-tenant deployment. An organization's own credentials (Step W-4.1) win over them, and
    // everything is resolved through `App\Services\WhatsApp\WhatsAppCredentials`: never read in a
    // caller, never logged, and the auth token is encrypted at rest on the organization.
    //
    // `templates` maps each `App\Support\WhatsApp\MessageTemplate` to the `ContentSid` (`HX…`)
    // Twilio returns once WhatsApp has approved that copy. They are per-account, which is why they
    // are config and not code; `php artisan whatsapp:templates` prints what to submit. A template
    // with no SID is refused before anything is sent, rather than failing at Twilio.
    //
    // `WHATSAPP_CLIENT=null` binds the no-op client and turns WhatsApp off everywhere; leaving the
    // credentials unset is already safe (every send reports not-configured without calling anyone).
    'whatsapp' => [
        'client' => env('WHATSAPP_CLIENT', 'twilio'),
        'base_url' => env('WHATSAPP_BASE_URL') ?: 'https://api.twilio.com',
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        // The WhatsApp-enabled sender, E.164 and without Twilio's `whatsapp:` prefix.
        'from' => env('TWILIO_WHATSAPP_FROM'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        // Where Twilio reports delivery (Step W-3.1). Must be https and publicly reachable, so it
        // stays unset in local development — a callback URL Twilio cannot reach fails the send.
        'status_callback_url' => env('TWILIO_STATUS_CALLBACK_URL'),
        // Step W-1.1: how long somebody must wait before asking for another verification code.
        'verification' => ['resend_seconds' => env('WHATSAPP_VERIFY_RESEND_SECONDS', 60)],
        // Step W-3.2: a ceiling per organization per minute, so a fan-out bug cannot become a few
        // thousand WhatsApp messages. High enough that normal traffic never reaches it.
        'per_minute' => env('WHATSAPP_PER_MINUTE', 60),
        'templates' => [
            'wa_verify_code' => env('TWILIO_TEMPLATE_VERIFY_CODE'),
            'post_waiting_client' => env('TWILIO_TEMPLATE_POST_WAITING_CLIENT'),
            'post_scheduled_reminder' => env('TWILIO_TEMPLATE_POST_SCHEDULED_REMINDER'),
            'post_decided' => env('TWILIO_TEMPLATE_POST_DECIDED'),
            'post_publish_failed' => env('TWILIO_TEMPLATE_POST_PUBLISH_FAILED'),
            'approval_overdue' => env('TWILIO_TEMPLATE_APPROVAL_OVERDUE'),
            'invoice_issued' => env('TWILIO_TEMPLATE_INVOICE_ISSUED'),
            'campaign_brief_ready' => env('TWILIO_TEMPLATE_CAMPAIGN_BRIEF_READY'),
            'daily_digest' => env('TWILIO_TEMPLATE_DAILY_DIGEST'),
            'alima_notification' => env('TWILIO_TEMPLATE_NOTIFICATION'),
        ],
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
        'graph_base_url' => env('FACEBOOK_GRAPH_BASE_URL') ?: 'https://graph.facebook.com',
        'graph_version' => env('FACEBOOK_GRAPH_VERSION') ?: 'v20.0',
    ],

];
