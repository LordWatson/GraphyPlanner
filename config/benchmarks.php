<?php

/*
|--------------------------------------------------------------------------
| Competitor benchmarking (Phase GTM-4)
|--------------------------------------------------------------------------
|
| `benchmarks:sync` reads each tracked profile's public figures once a night. The caps are about
| somebody else's rate limits, not ours: a profile already read today is skipped, and a run stops
| after `max_profiles_per_org` so one large roster can't spend an org's whole Graph budget.
|
| `window_days` is how far back the benchmark panel and the report block look for readings.
|
*/

return [

    'max_profiles_per_org' => (int) env('BENCHMARKS_MAX_PROFILES_PER_ORG', 50),

    'window_days' => (int) env('BENCHMARKS_WINDOW_DAYS', 30),

    // How many recent posts the average-engagement figure is drawn from, where the platform
    // reports per-post interactions at all.
    'engagement_posts' => (int) env('BENCHMARKS_ENGAGEMENT_POSTS', 12),

];
