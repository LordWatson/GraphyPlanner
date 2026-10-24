<?php

namespace App\Enums;

/**
 * Step M-9.3 — the delivery state of an outbound reply the studio sent through the vendor. A reply
 * is written `pending`, flipped to `sent` once `CommentSource::reply()` confirms the vendor accepted
 * it, or `failed` (with a user-safe `delivery_error`) so the composer can offer a retry. Inbound
 * messages leave this null.
 */
enum MessageDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }
}
