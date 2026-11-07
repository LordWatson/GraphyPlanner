<?php

namespace App\Enums;

use App\Models\AudienceContact;

/**
 * Step GTM-2.2 — the three automated emails, and the one thing that differs between them: whether
 * the person has to have confirmed marketing consent first.
 *
 * `welcome` is marketing — it only exists because they confirmed. `purchase` and `abandoned` are
 * about the person's **own** order or own unfinished checkout, so they are transactional and go out
 * without a marketing opt-in. Every one of them, including the transactional pair, is stopped by an
 * unsubscribe ({@see AudienceContact::isMailable()}).
 *
 * This is stricter than UK PECR, which would allow marketing similar products to a buyer on the
 * soft opt-in: a buyer here never receives a broadcast until they confirm.
 */
enum AudienceTrigger: string
{
    case Welcome = 'welcome';
    case Purchase = 'purchase';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::Purchase => 'Purchase follow-up',
            self::Abandoned => 'Abandoned checkout',
        };
    }

    /**
     * Whether this email needs confirmed marketing consent, as opposed to simply not having been
     * unsubscribed from.
     */
    public function requiresConsent(): bool
    {
        return $this === self::Welcome;
    }
}
