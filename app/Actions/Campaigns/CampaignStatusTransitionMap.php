<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;

/**
 * Step C-0.3 — the explicit `Campaign::status` state machine, mirroring `PostStatusTransitionMap`.
 * Status is the team's intent (plan §1.3.3); dates never move it automatically.
 */
class CampaignStatusTransitionMap
{
    /**
     * @var array<string, list<string>>
     */
    public const MAP = [
        CampaignStatus::Planning->value => [
            CampaignStatus::Active->value,
            CampaignStatus::Archived->value,
        ],
        CampaignStatus::Active->value => [
            CampaignStatus::Paused->value,
            CampaignStatus::Completed->value,
        ],
        CampaignStatus::Paused->value => [
            CampaignStatus::Active->value,
            CampaignStatus::Completed->value,
        ],
        CampaignStatus::Completed->value => [
            CampaignStatus::Archived->value,
            // Reopen.
            CampaignStatus::Active->value,
        ],
        CampaignStatus::Archived->value => [
            // Restore.
            CampaignStatus::Planning->value,
        ],
    ];

    public static function isAllowed(CampaignStatus $from, CampaignStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value], true);
    }

    /**
     * @return list<CampaignStatus>
     */
    public static function allowedFrom(CampaignStatus $from): array
    {
        return array_map(
            static fn (string $value): CampaignStatus => CampaignStatus::from($value),
            self::MAP[$from->value],
        );
    }

    /**
     * The verb the UI shows for a move ("Launch", "Pause", "Reopen"…).
     */
    public static function actionLabel(CampaignStatus $from, CampaignStatus $to): string
    {
        return match (true) {
            $from === CampaignStatus::Planning && $to === CampaignStatus::Active => 'Launch',
            $from === CampaignStatus::Paused && $to === CampaignStatus::Active => 'Resume',
            $from === CampaignStatus::Completed && $to === CampaignStatus::Active => 'Reopen',
            $from === CampaignStatus::Archived && $to === CampaignStatus::Planning => 'Restore',
            $to === CampaignStatus::Paused => 'Pause',
            $to === CampaignStatus::Completed => 'Mark complete',
            $to === CampaignStatus::Archived => 'Archive',
            default => $to->label(),
        };
    }
}
