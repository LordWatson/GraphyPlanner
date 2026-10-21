<?php

namespace App\Enums;

/**
 * Step M-3.3 — how a client's monthly report is produced on the 1st (org timezone).
 */
enum ClientReportSchedule: string
{
    /** A draft is prepared for the team to add commentary and publish. */
    case Review = 'review';

    /** Prepared, published to the portal and emailed to the client straight away. */
    case Automatic = 'automatic';

    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Prepare a draft for us to review',
            self::Automatic => 'Publish and email automatically',
            self::Off => 'No monthly report',
        };
    }
}
