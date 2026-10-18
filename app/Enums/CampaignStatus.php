<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    // Step C-0.3: a live campaign the team has stopped on purpose (C-6.1 unschedules its posts).
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Statuses a campaign is "being worked on" in: listed in pickers, counted on Home.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Planning, self::Active, self::Paused];
    }
}
