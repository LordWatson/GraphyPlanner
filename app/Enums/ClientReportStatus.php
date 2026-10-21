<?php

namespace App\Enums;

/**
 * Step M-3.3 — a monthly client report is a draft (staff only, figures refreshable) until it's
 * published (portal + email, figures frozen).
 */
enum ClientReportStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
