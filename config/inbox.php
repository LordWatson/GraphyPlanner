<?php

/*
|--------------------------------------------------------------------------
| Social inbox (Phase M-9)
|--------------------------------------------------------------------------
|
| `sync`: the `inbox:sync-comments` sweep (Step M-9.1), scheduled hourly. It pulls comments for
| recently published channels on comment-supporting platforms (config/platforms.php `comments`).
| - `window_days`: channels published more recently than this keep getting polled for new comments.
| - `min_interval_minutes`: a target polled more recently than this is skipped (unless `--force`),
|   so a manual refresh or an overlapping run doesn't hammer the vendor. Comment reading is
|   pull-based (there's no webhook), so this is the freshness knob — lower is fresher and costlier.
| - `max_targets_per_org`: cap per organization per run; the oldest-polled channels go first, the
|   rest next run. Keeps us inside Upload-Post's per-key rate limits.
|
| `sla`: the default reply SLA (hours) applied when an org hasn't set `organizations.inbox_sla_hours`.
| A conversation that needs a reply and has sat longer than the SLA with no studio response is
| "overdue" (InboxSlaService), surfaced on Home and nudged once a day by `inbox:overdue-reminders`.
|
*/

return [

    'sync' => [
        'window_days' => (int) env('INBOX_SYNC_WINDOW_DAYS', 30),
        'min_interval_minutes' => (int) env('INBOX_SYNC_MIN_INTERVAL_MINUTES', 55),
        'max_targets_per_org' => (int) env('INBOX_SYNC_MAX_TARGETS_PER_ORG', 200),
    ],

    'sla' => [
        'default_hours' => (int) env('INBOX_SLA_DEFAULT_HOURS', 24),
    ],

];
