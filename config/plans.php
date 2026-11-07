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
| Agency pricing (Nov 2026 review, Step GTM-0.1): Starter £49/$59, Standard £99/$119, Scale
| £199/$239, tiered on brands and seats. Cloud Campaign charges $199/$299 for less product, so the
| top tier undercuts it. `agency_legacy` is the pre-GTM-0.1 unlimited plan, kept non-public so the
| agency orgs that predate paid tiers keep exactly what they had; `plans:end-grandfather` moves
| them onto `agency_standard` when `organizations.plan_grandfathered_until` passes (12 months).
|
*/

$limitDefaults = array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::limits()), null);
$gateDefaults = array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), false);

return [

    /*
    | The plan assigned to a new org of each workspace type when it has no explicit plan. Businesses
    | and creators start on Free; since Step GTM-0.1 a new agency starts on the cheapest paid tier
    | rather than the old unlimited plan, so an unpaid agency org is capped like a Starter until it
    | subscribes (or a super admin overrides it — Step CG-2.6).
    */
    'defaults' => [
        'agency' => 'agency_starter',
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
                // Step GTM-4.1: every new limit must be set on every plan — `$limitDefaults` is
                // null, which means *unlimited*, so forgetting one gives it away for free.
                Feature::TrackedProfiles->value => 0,
            ],
            'features' => [
                ...$gateDefaults,
                // Step GTM-1.3 (closes the CG plan's open question §5.6): the comment inbox is the
                // one gate Free keeps. Buffer includes it on every plan, it is the surface that
                // brings someone back daily, and its cost here is bounded by Free's 3 accounts.
                Feature::Inbox->value => true,
            ],
            'highlights' => [
                '3 connected accounts',
                '30 scheduled posts a month',
                'Comments and replies in one inbox',
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
                Feature::TrackedProfiles->value => 3,
            ],
            'features' => [
                ...$gateDefaults,
                Feature::GridPlanner->value => true,
                Feature::Inbox->value => true,
                Feature::CompetitorBenchmarks->value => true,
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
            'tagline' => 'Get paid for your work, and stay on the right side of the rules.',
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
                Feature::TrackedProfiles->value => 3,
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
                // Step GTM-3.2: a creator's bio page on their own hostname. White labelling is
                // deliberately **not** here — it is for an agency putting its name on surfaces its
                // own clients see, which a single-brand creator has no use for.
                Feature::CustomDomain->value => true,
                Feature::CompetitorBenchmarks->value => true,
            ],
            // Step GTM-0.2, route (a): lead on what Planoly ($14), Stan and Beacons don't have —
            // the deals tracker, disclosure that actually blocks a publish, and a media kit built
            // from real numbers. The store and the bio page are table stakes in this segment now,
            // so they support the pitch instead of making it.
            'highlights' => [
                'Brand deals tracker — who owes what, and what has gone out',
                'Disclosure enforcement — an undisclosed paid post cannot publish',
                'Public media kit from your real numbers, with a sponsor preview',
                'Everything in Pro, plus link in bio and a 0%-fee store',
                '2 seats',
            ],
        ],

        /*
        | Step GTM-0.1 — the agency tiers. Every agency plan grants every gate: these are all the
        | same product, tiered on capacity (brands, seats, connected accounts), not on features.
        | White label and ads reporting will differentiate Standard and Scale when Phases GTM-3 and
        | GTM-5 build them; until then neither is advertised, because neither exists.
        |
        | AI credits are deliberately left unlimited on every agency tier, as they were before this
        | step. That is an open cost question, not a decision — see `.junie/modules/plans.md`.
        */
        'agency_starter' => [
            'name' => 'Agency Starter',
            'segment' => 'agency',
            'tagline' => 'For a freelancer or a small studio with a handful of brands.',
            'public' => true,
            'trial_days' => 14,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 49, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STARTER_GBP_MONTHLY')],
                    'annual' => ['amount' => 490, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STARTER_GBP_ANNUAL')],
                ],
                'usd' => [
                    'monthly' => ['amount' => 59, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STARTER_USD_MONTHLY')],
                    'annual' => ['amount' => 590, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STARTER_USD_ANNUAL')],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::Brands->value => 5,
                Feature::TeamSeats->value => 3,
                Feature::SocialAccounts->value => 50,
                Feature::TrackedProfiles->value => 25,
            ],
            'features' => [
                ...array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), true),
            ],
            'highlights' => [
                '5 client brands, 3 seats',
                'Campaigns end to end: brief, approve, report, bill',
                'Client portal and client invoicing',
                'Every feature — the tiers differ on size, not capability',
            ],
        ],

        'agency_standard' => [
            'name' => 'Agency',
            'segment' => 'agency',
            'tagline' => 'The full studio for managing many brands.',
            'public' => true,
            'trial_days' => 14,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 99, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_GBP_MONTHLY')],
                    'annual' => ['amount' => 990, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_GBP_ANNUAL')],
                ],
                'usd' => [
                    'monthly' => ['amount' => 119, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_USD_MONTHLY')],
                    'annual' => ['amount' => 1190, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_STANDARD_USD_ANNUAL')],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::Brands->value => 15,
                Feature::TeamSeats->value => 10,
                Feature::TrackedProfiles->value => 50,
                Feature::SocialAccounts->value => 150,
            ],
            'features' => [
                ...array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), true),
            ],
            'highlights' => [
                '15 client brands, 10 seats',
                'Everything in Starter, with room for a growing roster',
                'Grid planner, comment inbox and the full AI partner per brand',
                'Analytics and client reports across every brand',
            ],
        ],

        'agency_scale' => [
            'name' => 'Agency Scale',
            'segment' => 'agency',
            'tagline' => 'For a studio running a full roster.',
            'public' => true,
            'trial_days' => 14,
            'prices' => [
                'gbp' => [
                    'monthly' => ['amount' => 199, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_SCALE_GBP_MONTHLY')],
                    'annual' => ['amount' => 1990, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_SCALE_GBP_ANNUAL')],
                ],
                'usd' => [
                    'monthly' => ['amount' => 239, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_SCALE_USD_MONTHLY')],
                    'annual' => ['amount' => 2390, 'stripe_price_id' => env('STRIPE_PRICE_AGENCY_SCALE_USD_ANNUAL')],
                ],
            ],
            'limits' => [
                ...$limitDefaults,
                Feature::Brands->value => 50,
                Feature::TeamSeats->value => null,
                Feature::SocialAccounts->value => 500,
                Feature::TrackedProfiles->value => 100,
            ],
            'features' => [
                ...array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::gates()), true),
            ],
            'highlights' => [
                '50 client brands, unlimited seats',
                'Everything in Agency',
                'Priority support',
            ],
        ],

        /*
        | The pre-GTM-0.1 unlimited agency plan. Not public and never sold: the data migration that
        | introduced the paid tiers moved every existing agency org onto this so none of them lost
        | capacity overnight, with `organizations.plan_grandfathered_until` set 12 months out.
        */
        'agency_legacy' => [
            'name' => 'Agency (legacy)',
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
