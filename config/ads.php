<?php

/*
|--------------------------------------------------------------------------
| Paid-ads reporting (Phase GTM-5)
|--------------------------------------------------------------------------
|
| `provider`: `null` (no figures — the default) or `meta` (the Marketing API's insights endpoint).
| Meta needs `ads_read` on our own Facebook app, which is Phase 5 work; until that is granted every
| read comes back empty and the report shows gaps rather than wrong numbers.
|
| `window_days`: how far back a nightly sync asks for, and how far back the report and the panel
| look. Ad platforms restate recent days as conversions land, which is why the sync re-reads the
| whole window every night instead of only yesterday.
|
| READ-ONLY: there is no budget, campaign or spend setting here, by design. See `App\Contracts\AdsSource`.
|
*/

return [

    'provider' => env('ADS_PROVIDER'),

    'window_days' => (int) env('ADS_WINDOW_DAYS', 30),

];
