<?php

namespace App\Enums;

/**
 * Per-target publish outcome (Step 1.4), set by `PublishPostJob` once
 * `PublishAdapter::publish()` returns a `TargetResult` for this target.
 */
enum PostTargetStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }
}
