<?php

/*
|--------------------------------------------------------------------------
| Creator payments (Phase CG-4)
|--------------------------------------------------------------------------
|
| The creator store's money, which is NOT our subscription billing. Our own subscriptions run on
| Cashier with `STRIPE_KEY`/`STRIPE_SECRET` + `STRIPE_WEBHOOK_SECRET` (config/cashier.php); creators'
| sales run on Stripe Connect with the separate platform key and webhook secret below. Per the
| plan's Principle 4 the two integrations share no tables, webhooks or config keys — don't point
| these at the same env vars.
|
| `platform_fee_percent` is 0 and is meant to stay 0 (Principle 3: Alima makes its money from the
| subscription, not a cut of what creators sell). It exists so the one place that would have to
| change is named, not so it can be turned up quietly.
|
*/

return [

    // `stripe` (live Connect) or `null` (no provider — the store UI explains it isn't available).
    'provider' => env('PAYMENTS_PROVIDER', 'stripe'),

    'stripe' => [
        'secret' => env('STRIPE_CONNECT_SECRET'),
        'webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        // Where new connected accounts are opened unless the org's country says otherwise.
        'country' => env('STRIPE_CONNECT_COUNTRY', 'GB'),
    ],

    'platform_fee_percent' => 0,

    // Currencies a creator may price in (the first is the default).
    'currencies' => ['GBP', 'USD', 'EUR'],

    // How long a buyer's emailed download link lasts by default, and how many times it may be used.
    // Both are overridable per product (`products.settings`).
    'downloads' => [
        'expiry_hours' => 72,
        'limit' => 5,
    ],

    // Bookings: the defaults a new booking product starts with.
    'bookings' => [
        'slot_minutes' => 30,
        'buffer_minutes' => 0,
        'lead_hours' => 24,
        'days_ahead' => 30,
    ],

];
