<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Strategist = 'strategist';
    case Designer = 'designer';
    case ClientReviewer = 'client_reviewer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Strategist => 'Strategist',
            self::Designer => 'Designer',
            self::ClientReviewer => 'Client reviewer',
            self::Viewer => 'Viewer',
        };
    }
}
