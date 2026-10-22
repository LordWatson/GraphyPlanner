<?php

/*
|--------------------------------------------------------------------------
| Tax rates (Step M-4.1)
|--------------------------------------------------------------------------
|
| The starter tax rates seeded for every new organization. They're ordinary editable rows once
| created — an org adds, edits or archives its own rates in Settings — so adjusting them later (or
| to the tax adviser's exact wording, still pending per `.junie/modules/billing.md`) is data, not
| code. UK and UAE are seeded because the MVP targets both markets.
|
| Each entry: `name` (shown on the invoice as the tax line label) and `rate` (a percentage).
|
*/

return [

    'defaults' => [
        ['name' => 'UK VAT (20%)', 'rate' => 20.00],
        ['name' => 'UK Zero-rated (0%)', 'rate' => 0.00],
        ['name' => 'UK Exempt (0%)', 'rate' => 0.00],
        ['name' => 'UAE VAT (5%)', 'rate' => 5.00],
        ['name' => 'UAE Zero-rated (0%)', 'rate' => 0.00],
    ],

];
