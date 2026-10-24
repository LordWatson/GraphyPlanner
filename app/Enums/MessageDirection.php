<?php

namespace App\Enums;

/**
 * Step M-9.1 — whether an inbox message came from the public (`inbound`, a comment on our post) or
 * went out from the studio (`outbound`, a reply a human sent through the vendor). Only outbound
 * messages carry a `sent_by` user and a delivery status.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Inbound',
            self::Outbound => 'Reply',
        };
    }
}
