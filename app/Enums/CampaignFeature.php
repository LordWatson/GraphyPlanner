<?php

namespace App\Enums;

/**
 * Campaigns enhancement C-9.4 — the campaign features that can be switched off per organization
 * today (org settings) and per plan once entitlements land. Checked only through
 * `App\Contracts\CampaignFeatures`.
 */
enum CampaignFeature: string
{
    case Templates = 'templates';
    case AiPlanner = 'ai_planner';
    case WrapReports = 'wrap_reports';
    case Invoicing = 'invoicing';

    public function label(): string
    {
        return match ($this) {
            self::Templates => 'Campaign templates',
            self::AiPlanner => 'The AI campaign planner',
            self::WrapReports => 'Wrap reports',
            self::Invoicing => 'Campaign invoicing',
        };
    }

    public function offMessage(): string
    {
        return match ($this) {
            self::AiPlanner => 'The AI campaign planner isn’t switched on for your workspace.',
            self::Invoicing => 'Campaign invoicing isn’t switched on for your workspace.',
            default => "{$this->label()} aren’t switched on for your workspace.",
        };
    }
}
