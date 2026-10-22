<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    // Step M-4.2: the client has reported payment; the org hasn't confirmed it yet.
    case PendingConfirmation = 'pending_confirmation';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::PendingConfirmation => 'Payment reported',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}
