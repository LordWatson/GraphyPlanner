<?php

namespace App\Enums;

/**
 * Step W-3.1 — where one WhatsApp message got to. Twilio's own statuses, collapsed to the ones
 * that mean something different to an agency reading the log.
 */
enum WhatsAppMessageStatus: string
{
    /** Twilio accepted it; nothing has come back yet. */
    case Sent = 'sent';

    /** It reached the handset. */
    case Delivered = 'delivered';

    /** They opened it. */
    case Read = 'read';

    case Failed = 'failed';

    /** Nothing was sent: no consent, no number, a suppression, or WhatsApp switched off. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
            self::Skipped => 'Not sent',
        };
    }

    /**
     * Twilio's `MessageStatus`, which carries several values that mean the same thing to us
     * (`queued`, `sending`, `accepted`) and two that mean failure (`failed`, `undelivered`).
     */
    public static function fromProvider(string $status): ?self
    {
        return match (strtolower($status)) {
            'queued', 'sending', 'accepted', 'sent' => self::Sent,
            'delivered' => self::Delivered,
            'read' => self::Read,
            'failed', 'undelivered' => self::Failed,
            default => null,
        };
    }
}
