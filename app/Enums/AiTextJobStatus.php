<?php

namespace App\Enums;

use App\Models\AiTextJob;

/**
 * Step M-7.2 — lifecycle of an async agency text AI job ({@see AiTextJob}).
 */
enum AiTextJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * No more work will happen — the UI can stop polling.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Queued, self::Running => false,
        };
    }
}
