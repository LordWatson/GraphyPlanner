<?php

namespace App\Enums;

/**
 * Step M-9.2 — where a social-inbox conversation sits in the community-management workflow. `open`
 * is the default on ingestion; `snoozed` hides it until `snoozed_until`; `resolved` closes it. The
 * status is the thread's, not a single comment's — assignment, SLA and resolve all act on the thread.
 */
enum InboxStatus: string
{
    case Open = 'open';
    case Snoozed = 'snoozed';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Snoozed => 'Snoozed',
            self::Resolved => 'Resolved',
        };
    }

    /**
     * The stored values that still want the studio's attention (list defaults, counts).
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Open->value, self::Snoozed->value];
    }
}
