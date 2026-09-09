<?php

namespace App\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
        };
    }
}
