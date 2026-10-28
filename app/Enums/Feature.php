<?php

namespace App\Enums;

use App\Contracts\Entitlements;

/**
 * Step CG-2.1 / B-9.1 — the metered and gated capabilities a plan grants. Two kinds:
 *
 *  - **Limits** carry a numeric cap per org (`?int`, `null` = unlimited): how many social accounts,
 *    scheduled posts a month, team seats, AI credits, brands, days of analytics history, MB of media.
 *  - **Gates** are booleans: whether the plan includes the grid planner, the comment inbox, a public
 *    bio page, a creator store, the public media kit, the brand-deals tracker or email automation.
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

    // Boolean gates.
    case GridPlanner = 'grid_planner';
    case Inbox = 'inbox';
    case BioPage = 'bio_page';
    case Store = 'store';
    case PublicMediaKit = 'public_media_kit';
    case BrandDeals = 'brand_deals';
    case EmailAutomation = 'email_automation';

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
            self::MediaStorageMb => true,
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
            self::GridPlanner => 'Grid planner',
            self::Inbox => 'Comment inbox',
            self::BioPage => 'Link in bio',
            self::Store => 'Creator store',
            self::PublicMediaKit => 'Public media kit',
            self::BrandDeals => 'Brand deals tracker',
            self::EmailAutomation => 'Email automation',
        };
    }
}
