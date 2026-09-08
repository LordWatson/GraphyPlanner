<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Offboarding = 'offboarding';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Offboarding => 'Offboarding',
            self::Archived => 'Archived',
        };
    }
}
