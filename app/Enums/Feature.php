<?php

namespace App\Enums;

use App\Contracts\Entitlements;

/**
 * Step CG-2.1 / B-9.1 — the metered and gated capabilities a plan grants. Two kinds:
 *
 *  - **Limits** carry a numeric cap per org (`?int`, `null` = unlimited): how many social accounts,
 *    scheduled posts a month, team seats, AI credits, brands, days of analytics history, MB of media.
 *  - **Gates** are booleans: whether the plan includes the grid planner, the comment inbox, a public
 *    bio page, a creator store, the public media kit, the brand-deals tracker, email automation,
 *    white labelling or custom domains.
 *
 * Every plan in `config/plans.php` must define every feature (asserted by `PlanDefinitionsTest`), so
 * a new feature can never silently default to "unlimited"/"granted" for a plan nobody updated.
 * Enforcement lives in the Actions (never in controllers) through {@see Entitlements}.
 */
enum Feature: string
{
    // Numeric limits.
    case SocialAccounts = 'social_accounts';
    case ScheduledPostsPerMonth = 'scheduled_posts_per_month';
    case TeamSeats = 'team_seats';
    case AiTextCredits = 'ai_text_credits';
    case AiImageCredits = 'ai_image_credits';
    case Brands = 'brands';
    case AnalyticsHistoryDays = 'analytics_history_days';
    case MediaStorageMb = 'media_storage_mb';
    // Phase GTM-4: how many competitor profiles a plan may track. Each one is a nightly read of
    // somebody else's public profile, so it is a capacity limit, not a switch.
    case TrackedProfiles = 'tracked_profiles';

    // Boolean gates.
    case GridPlanner = 'grid_planner';
    case Inbox = 'inbox';
    case BioPage = 'bio_page';
    case Store = 'store';
    case PublicMediaKit = 'public_media_kit';
    case BrandDeals = 'brand_deals';
    case EmailAutomation = 'email_automation';
    // Phase GTM-3: an agency puts its own name on the surfaces its clients see, and a creator
    // serves their bio page on their own hostname.
    case WhiteLabel = 'white_label';
    case CustomDomain = 'custom_domain';
    case CompetitorBenchmarks = 'competitor_benchmarks';
    case AdsReporting = 'ads_reporting';
    /**
     * Step W-4.3 — WhatsApp notifications. A gate rather than a limit: Twilio bills the
     * conversations, so what a plan grants is the capability, not an allowance. Granted on every
     * agency plan (they all grant every gate) and off for business/creator, which is where the
     * pitch lives — "your client approves from their phone" is an agency sentence. Moving it onto
     * a business or creator plan is a pricing decision, not a code change.
     */
    case WhatsApp = 'whatsapp';

    /**
     * A numeric-capped feature (stored under a plan's `limits`).
     */
    public function isLimit(): bool
    {
        return match ($this) {
            self::SocialAccounts,
            self::ScheduledPostsPerMonth,
            self::TeamSeats,
            self::AiTextCredits,
            self::AiImageCredits,
            self::Brands,
            self::AnalyticsHistoryDays,
            self::MediaStorageMb,
            self::TrackedProfiles => true,
            default => false,
        };
    }

    /**
     * A boolean on/off feature (stored under a plan's `features`).
     */
    public function isGate(): bool
    {
        return ! $this->isLimit();
    }

    /**
     * @return list<self>
     */
    public static function limits(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->isLimit()));
    }

    /**
     * @return list<self>
     */
    public static function gates(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->isGate()));
    }

    /**
     * A short, human label used in upgrade prompts and the plan page.
     */
    public function label(): string
    {
        return match ($this) {
            self::SocialAccounts => 'Connected accounts',
            self::ScheduledPostsPerMonth => 'Scheduled posts a month',
            self::TeamSeats => 'Team seats',
            self::AiTextCredits => 'AI credits a month',
            self::AiImageCredits => 'AI image credits a month',
            self::Brands => 'Brands',
            self::AnalyticsHistoryDays => 'Analytics history',
            self::MediaStorageMb => 'Media storage',
            self::TrackedProfiles => 'Tracked competitor profiles',
            self::GridPlanner => 'Grid planner',
            self::Inbox => 'Comment inbox',
            self::BioPage => 'Link in bio',
            self::Store => 'Creator store',
            self::PublicMediaKit => 'Public media kit',
            self::BrandDeals => 'Brand deals tracker',
            self::EmailAutomation => 'Email automation',
            self::WhiteLabel => 'White label',
            self::CustomDomain => 'Custom domain',
            self::CompetitorBenchmarks => 'Competitor benchmarks',
            self::AdsReporting => 'Paid ads reporting',
            self::WhatsApp => 'WhatsApp notifications',
        };
    }
}
