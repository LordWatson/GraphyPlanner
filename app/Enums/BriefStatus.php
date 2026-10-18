<?php

namespace App\Enums;

/**
 * Step C-2.1 — where a campaign brief is in its sign-off. Editing a sent or approved brief puts it
 * back to draft, so a signed brief can never change silently.
 */
enum BriefStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'With the client',
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
        };
    }
}
