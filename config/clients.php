<?php

/*
|--------------------------------------------------------------------------
| Client health v2 (Step M-3.4 / I-6.1)
|--------------------------------------------------------------------------
|
| Thresholds `ClientHealthService` reads. Each signal becomes a weighted reason; the heaviest one
| is the client's headline reason and the worst tone is its health.
|
| - `approval_overdue_hours`: a post waiting on the client longer than this is overdue. (M-6.5's
|   per-organization approval SLA will replace this default.)
| - `failed_publish_days`: failed / partly published posts within this many days count.
| - `failed_publish_red`: this many failed posts in the window turns the client red.
| - `cadence_days` / `cadence_ratio`: published posts over the last `cadence_days`, against
|   `clients.target_posts_per_week`; below `cadence_ratio` of the target is flagged.
| - `calendar_gap_days`: an active client with nothing planned in the next N days is flagged.
| - `invoice_overdue_red_days`: an invoice overdue longer than this turns the client red (an
|   Owner-only reason).
|
*/

return [

    'health' => [
        'approval_overdue_hours' => 48,
        'failed_publish_days' => 7,
        'failed_publish_red' => 3,
        'cadence_days' => 28,
        'cadence_ratio' => 0.75,
        'calendar_gap_days' => 7,
        'invoice_overdue_red_days' => 30,
    ],

];
