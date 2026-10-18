<?php

namespace App\Enums;

/**
 * Step C-0.2 — what a campaign is for. Drives the planner's suggestions and frames comparisons
 * between campaigns (C-7.4) so an awareness push isn't judged on sales.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case Engagement = 'engagement';
    case Traffic = 'traffic';
    case Leads = 'leads';
    case Sales = 'sales';
    case Launch = 'launch';
    case Community = 'community';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Awareness => 'Awareness',
            self::Engagement => 'Engagement',
            self::Traffic => 'Traffic',
            self::Leads => 'Leads',
            self::Sales => 'Sales',
            self::Launch => 'Launch',
            self::Community => 'Community',
            self::Other => 'Other',
        };
    }
}
