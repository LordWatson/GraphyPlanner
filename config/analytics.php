<?php

/*
|--------------------------------------------------------------------------
| Engagement analytics (Phase M-3)
|--------------------------------------------------------------------------
|
| `engagement`: the `analytics:sync-engagement` sweep (Step M-3.1), scheduled every 30 minutes.
| - `window_days`: published channels younger than this keep getting fresh readings.
| - `min_interval_hours`: a channel or account read more recently than this is skipped, so the
|   30-minute sweep (or a manual refresh) only re-reads a given post about once an hour rather than
|   every run — a freshly published post is still picked up within ~30 min, but the vendor isn't
|   hammered. Drop to 0 for maximum freshness (every run re-reads every post), raise it to ease off.
| - `max_posts_per_org`: cap per organization per run. Upload-Post's live post endpoint allows
|   100 requests per 5 minutes per key; the oldest-read channels go first, the rest next run.
|
| `best_time` (Step M-3.2): slots are a weekday + a `block_hours` window in the account's timezone,
| over posts published in the last `lookback_days`. A slot needs `min_posts` measured posts before it
| is suggested; an account needs `min_account_posts` overall. Up to `slots` are suggested.
|
| `client_reports` (Step M-3.3): from `send_day` at `send_hour` (org timezone) each month, last
| month's report is drafted for review, or published and emailed for "automatic" clients. The hour
| gives the morning's engagement syncs time to land first.
|
*/

return [

    'engagement' => [
        'window_days' => (int) env('ANALYTICS_ENGAGEMENT_WINDOW_DAYS', 90),
        'min_interval_hours' => (int) env('ANALYTICS_ENGAGEMENT_MIN_INTERVAL_HOURS', 1),
        'max_posts_per_org' => (int) env('ANALYTICS_ENGAGEMENT_MAX_POSTS_PER_ORG', 300),
    ],

    'best_time' => [
        'lookback_days' => 180,
        'block_hours' => 2,
        'min_posts' => 2,
        'min_account_posts' => 6,
        'slots' => 3,

        // Step B-8.3: sensible fallback slots when a brand's own history is still too thin to
        // learn from. One slot per platform (ISO weekday 1=Mon..7=Sun, and an hour **local to the
        // account's timezone**), drawn from widely-cited best-posting-time research. `default`
        // catches any platform without an entry. These only ever apply to a `business` workspace's
        // BestTimeService fallback — the agency/data-driven path is unchanged.
        'defaults' => [
            'instagram' => [3, 11],      // Wed 11:00
            'facebook' => [3, 13],       // Wed 13:00
            'tiktok' => [5, 19],         // Fri 19:00
            'linkedin' => [2, 8],        // Tue 08:00
            'youtube' => [6, 15],        // Sat 15:00
            'threads' => [3, 10],        // Wed 10:00
            'pinterest' => [6, 20],      // Sat 20:00
            'x' => [3, 9],               // Wed 09:00
            'google_business' => [2, 10], // Tue 10:00
            'default' => [3, 10],        // Wed 10:00
        ],
    ],

    'client_reports' => [
        'send_day' => 1,
        'send_hour' => 9,
    ],

    // Step B-8.2: the weekly business Insights email digest goes out on Monday at this local hour
    // (the brand's timezone), caught from one hourly schedule so every timezone gets it on Monday
    // morning. Idempotent per ISO week.
    'insights_digest' => [
        'send_hour' => (int) env('ANALYTICS_INSIGHTS_DIGEST_HOUR', 8),
    ],

];
