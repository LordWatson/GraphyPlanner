<?php

namespace App\Enums;

enum ApprovalMode: string
{
    case InternalOnly = 'internal_only';
    case ClientRequired = 'client_required';

    public function label(): string
    {
        return match ($this) {
            self::InternalOnly => 'Internal only',
            self::ClientRequired => 'Client approval required',
        };
    }
}
