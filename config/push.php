<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Web push (Step B-7.4)
    |--------------------------------------------------------------------------
    |
    | Per-user browser push for business workspaces — "post goes out in 30
    | minutes", publish failures and "nothing scheduled tomorrow". Delivery sits
    | behind the `App\Contracts\PushNotifier` contract, so the VAPID transport
    | can be swapped without touching callers.
    |
    | `enabled` gates the whole feature (and the opt-in UI). It needs a VAPID key
    | pair — generate one once and keep the private key secret. Until a real
    | transport is wired the default notifier logs instead of sending, so the
    | rest of the pipeline (subscription storage, opt-out, reminders) is testable
    | and shippable dark.
    |
    */
    'enabled' => (bool) env('PUSH_ENABLED', false),

    // Step CG-1.3: which delivery transport backs `App\Contracts\PushNotifier`. `log` (the default)
    // is the no-op logger — the whole opt-in/reminder pipeline runs but nothing leaves the server, so
    // dev and the test suite stay dependency-free. `webpush` binds the real VAPID transport
    // (`minishlink/web-push`); set it in production once the VAPID keys are in the environment.
    'transport' => env('PUSH_TRANSPORT', 'log'),

    'vapid' => [
        'subject' => env('PUSH_VAPID_SUBJECT', env('APP_URL', 'https://alima.app')),
        'public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('PUSH_VAPID_PRIVATE_KEY'),
    ],

    // How far ahead of a scheduled target to fire the "goes out soon" reminder, and the sweep
    // window the command scans each run (it runs every 5 minutes, so the window must cover one tick
    // plus a little slack). Minutes.
    'reminder_lead_minutes' => (int) env('PUSH_REMINDER_LEAD_MINUTES', 30),
    'reminder_window_minutes' => (int) env('PUSH_REMINDER_WINDOW_MINUTES', 10),
];
