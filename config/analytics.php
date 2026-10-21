<?php

/*
|--------------------------------------------------------------------------
| Engagement analytics (Phase M-3)
|--------------------------------------------------------------------------
|
| `engagement`: the nightly `analytics:sync-engagement` sweep (Step M-3.1).
| - `window_days`: published channels younger than this keep getting fresh readings.
| - `min_interval_hours`: a channel or account read more recently than this is skipped, so a
|   re-run (or a manual refresh) never hammers the vendor.
| - `max_posts_per_org`: cap per organization per run. Upload-Post's live post endpoint allows
|   100 requests per 5 minutes per key; the oldest-read channels go first, the rest next run.
|
| `best_time` (Step M-3.2): slots are a weekday + a `block_hours` window in the account's timezone,
| over posts published in the last `lookback_days`. A slot needs `min_posts` measured posts before it
| is suggested; an account needs `min_account_posts` overall. Up to `slots` are suggested.
|
| `client_reports` (Step M-3.3): from `send_day` at `send_hour` (org timezone) each month, last
| month's report is drafted for review, or published and emailed for "automatic" clients. The hour
| leaves time for the nightly engagement sync (04:00 UTC) to land first.
|
*/

return [

    'engagement' => [
        'window_days' => (int) env('ANALYTICS_ENGAGEMENT_WINDOW_DAYS', 90),
        'min_interval_hours' => (int) env('ANALYTICS_ENGAGEMENT_MIN_INTERVAL_HOURS', 20),
        'max_posts_per_org' => (int) env('ANALYTICS_ENGAGEMENT_MAX_POSTS_PER_ORG', 300),
    ],

    'best_time' => [
        'lookback_days' => 180,
        'block_hours' => 2,
        'min_posts' => 2,
        'min_account_posts' => 6,
        'slots' => 3,
    ],

    'client_reports' => [
        'send_day' => 1,
        'send_hour' => 9,
    ],

];
