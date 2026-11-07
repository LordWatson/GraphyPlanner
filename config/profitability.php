<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Effort weights (minutes)
    |--------------------------------------------------------------------------
    |
    | How much work each thing the app can *see* represents. Effort is inferred rather than timed,
    | because nobody fills in timesheets — and a profitability figure nobody maintains is worth
    | less than an estimated one everybody can see.
    |
    | These are starting points, not truths. They are configurable precisely because an agency's
    | own numbers will differ, and the figure is labelled an estimate wherever it is shown. A
    | client's `estimated_hours_per_month` overrides the whole calculation for agencies that do
    | track their time.
    |
    | A revision round is weighted heavily on purpose: re-cutting and re-approving a post costs far
    | more than producing the first version, and "revisions climbing while the retainer stays flat"
    | is the pattern that quietly destroys an agency's margin.
    |
    */

    'minutes' => [
        'post' => (int) env('PROFIT_MINUTES_POST', 45),
        'revision_round' => (int) env('PROFIT_MINUTES_REVISION', 30),
        'asset' => (int) env('PROFIT_MINUTES_ASSET', 10),
        'inbox_reply' => (int) env('PROFIT_MINUTES_INBOX_REPLY', 4),
        'report' => (int) env('PROFIT_MINUTES_REPORT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default blended hourly rate (minor units)
    |--------------------------------------------------------------------------
    |
    | Used when an organization has not set its own. Zero means "not configured", and the
    | profitability figures then report effort only and say the rate is missing, rather than
    | printing a margin of 100% that happens to be arithmetically true and completely useless.
    |
    */

    'default_hourly_rate_minor' => (int) env('PROFIT_HOURLY_RATE_MINOR', 0),

    /*
    |--------------------------------------------------------------------------
    | Revision trend window (months)
    |--------------------------------------------------------------------------
    |
    | How far back to compare revision rounds when deciding whether they are climbing. Three months
    | against the three before it: long enough to see a trend, short enough to still be about now.
    |
    */

    'trend_months' => (int) env('PROFIT_TREND_MONTHS', 3),

];
