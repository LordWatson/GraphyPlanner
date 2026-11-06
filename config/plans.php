<?php

use App\Enums\Feature;

/*
|--------------------------------------------------------------------------
| Plans & entitlements (Phase CG-2 — Step CG-2.1 / B-9.1)
|--------------------------------------------------------------------------
|
| The single source of truth for what each plan costs and what it unlocks. Limits are read by
| App\Services\Plans\ConfigEntitlements (null = unlimited) and the plan page/pricing table are
| generated straight from here, so prices can never drift from enforcement.
|
| Pricing (Oct 2026 competitor review, Step CG-0.1): Free £0, Pro £15/mo, Creator Pro £29/mo.
| USD set a little higher ($19 / $37). Annual = two months free (monthly × 10). These are the
| customer-facing figures; the real Stripe Price IDs are wired per environment through the env vars
| below once they exist in the Stripe dashboard (none are created yet — the app ships billing dark
| behind the super-admin switch until they are).
|
*/

$limitDefaults = array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::limits()), null);
$gateDefaults = array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), false);

return [

    /*
    | The plan assigned to a new org of each workspace type when it has no explicit plan.
    | Agencies are unlimited (today's product), businesses/creators start on Free.
    */
    'defaults' => [
        'agency' => 'agency_standard',
        'business' => 'business_free',
    ],

    /*
    | Billing currencies offered at checkout and on the pricing table. The first is the fallback.
    */
    'currencies' => [
        'gbp' => ['code' => 'GBP', 'symbol' => '£'],
        'usd' => ['code' => 'USD', 'symbol' => '$'],
    ],

    'annual_discount_label' => '2 months free',

    'plans' => [

        'business_free' => [
            'name' => 'Free',
            'segment' => 'business',
            'tagline' => 'Plan and publish for one brand, on us.',
            'public' => true,
            'trial_days' => 0,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 0, 'stripe_price_id' => null],
                    'annual' => ['amount' => 0, 'stripe_price_id' => null],
                ],
                'usd' => [
                    'monthly' => ['amount' => 0, 'stripe_price_id' => null],
                    'annual' => ['amount' => 0, 'stripe_price_id' => null],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::SocialAccounts->value => 3,
                Feature::ScheduledPostsPerMonth->value => 30,
                Feature::TeamSeats->value => 1,
                Feature::AiTextCredits->value => 30,
                Feature::AiImageCredits->value => 5,
                Feature::Brands->value => 1,
                Feature::AnalyticsHistoryDays->value => 30,
                Feature::MediaStorageMb->value => 500,
            ],
            'features' => [
                ...$gateDefaults,
            ],
            'highlights' => [
                '3 connected accounts',
                '30 scheduled posts a month',
                'A taste of the AI partner',
                'Insights with plain-language takeaways',
            ],
        ],

        'business_pro' => [
            'name' => 'Pro',
            'segment' => 'business',
            'tagline' => 'The AI marketing partner for one brand.',
            'public' => true,
            'trial_days' => 14,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 15, 'stripe_price_id' => env('STRIPE_PRICE_BUSINESS_PRO_GBP_MONTHLY')],
                    'annual' => ['amount' => 150, 'stripe_price_id' => env('STRIPE_PRICE_BUSINESS_PRO_GBP_ANNUAL')],
                ],
                'usd' => [
                    'monthly' => ['amount' => 19, 'stripe_price_id' => env('STRIPE_PRICE_BUSINESS_PRO_USD_MONTHLY')],
                    'annual' => ['amount' => 190, 'stripe_price_id' => env('STRIPE_PRICE_BUSINESS_PRO_USD_ANNUAL')],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::SocialAccounts->value => null,
                Feature::ScheduledPostsPerMonth->value => null,
                Feature::TeamSeats->value => 1,
                Feature::AiTextCredits->value => 500,
                Feature::AiImageCredits->value => 50,
                Feature::Brands->value => 1,
                Feature::AnalyticsHistoryDays->value => 365,
                Feature::MediaStorageMb->value => 5000,
            ],
            'features' => [
                ...$gateDefaults,
                Feature::GridPlanner->value => true,
                Feature::Inbox->value => true,
            ],
            'highlights' => [
                'All nine networks, one-tap connect',
                'Unlimited scheduling',
                'The full AI partner (plan my week, repurpose, brand-fit)',
                'Grid planner & comment inbox',
                'Insights, best times & weekly digest',
            ],
        ],

        'creator_pro' => [
            'name' => 'Creator Pro',
            'segment' => 'creator',
            'tagline' => 'Everything in Pro, plus a storefront that pays you 100%.',
            'public' => true,
            'trial_days' => 14,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 29, 'stripe_price_id' => env('STRIPE_PRICE_CREATOR_PRO_GBP_MONTHLY')],
                    'annual' => ['amount' => 290, 'stripe_price_id' => env('STRIPE_PRICE_CREATOR_PRO_GBP_ANNUAL')],
                ],
                'usd' => [
                    'monthly' => ['amount' => 37, 'stripe_price_id' => env('STRIPE_PRICE_CREATOR_PRO_USD_MONTHLY')],
                    'annual' => ['amount' => 370, 'stripe_price_id' => env('STRIPE_PRICE_CREATOR_PRO_USD_ANNUAL')],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::SocialAccounts->value => null,
                Feature::ScheduledPostsPerMonth->value => null,
                Feature::TeamSeats->value => 2,
                Feature::AiTextCredits->value => 1000,
                Feature::AiImageCredits->value => 100,
                Feature::Brands->value => 1,
                Feature::AnalyticsHistoryDays->value => 365,
                Feature::MediaStorageMb->value => 10000,
            ],
            'features' => [
                ...$gateDefaults,
                Feature::GridPlanner->value => true,
                Feature::Inbox->value => true,
                Feature::BioPage->value => true,
                Feature::Store->value => true,
                Feature::PublicMediaKit->value => true,
                Feature::BrandDeals->value => true,
                Feature::EmailAutomation->value => true,
            ],
            'highlights' => [
                'Everything in Pro',
                'Public media kit & deals tracker',
                'Link in bio',
                'Sell at a 0% platform fee',
                '2 seats',
            ],
        ],

        /*
        | Agencies keep today's product: no limits, every feature on. Not shown on the public
        | creator/business pricing table (`public => false`).
        */
        'agency_standard' => [
            'name' => 'Agency',
            'segment' => 'agency',
            'tagline' => 'The full studio for managing many brands.',
            'public' => false,
            'trial_days' => 0,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 0, 'stripe_price_id' => null],
                    'annual' => ['amount' => 0, 'stripe_price_id' => null],
                ],
                'usd' => [
                    'monthly' => ['amount' => 0, 'stripe_price_id' => null],
                    'annual' => ['amount' => 0, 'stripe_price_id' => null],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
            ],
            'features' => [
                ...array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), true),
            ],
            'highlights' => [
                'Unlimited clients, accounts and seats',
                'Every feature, no caps',
            ],
        ],

    ],

];
