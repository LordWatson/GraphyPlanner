<?php

/*
|--------------------------------------------------------------------------
| Affiliate / referral programme (Step GTM-7.3)
|--------------------------------------------------------------------------
|
| The commission a new partner is created with, and where applications go. Each partner's own
| `commission_percent` overrides the default, so a deal struck with one publisher doesn't change
| anybody else's.
|
| Attribution lives on `organizations.referral_partner_id` — the org is already the Cashier
| billable, so it is the thing a commission is owed on.
|
*/

return [

    'default_commission_percent' => (int) env('REFERRAL_COMMISSION_PERCENT', 20),

    'contact_email' => env('REFERRAL_CONTACT_EMAIL', 'partners@alima.com'),

];
