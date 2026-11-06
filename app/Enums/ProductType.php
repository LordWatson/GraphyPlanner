<?php

namespace App\Enums;

/**
 * Step CG-4.2 — what a creator sells from their bio page. The type decides how an order is
 * fulfilled, not how it's paid for:
 *
 *  - `download` — a file from the media library, emailed as a signed, expiring link (CG-4.3).
 *  - `booking` — a slot from the product's availability windows, in the brand's timezone (CG-4.5).
 *  - `tip` — the buyer names the amount; there is nothing to deliver.
 *  - `qa` — a paid question. The card is **authorised** at checkout and only captured when the
 *    creator answers, so nobody pays for an answer they never got.
 */
enum ProductType: string
{
    case Download = 'download';
    case Booking = 'booking';
    case Tip = 'tip';
    case Qa = 'qa';

    public function label(): string
    {
        return match ($this) {
            self::Download => 'Digital download',
            self::Booking => 'Booking',
            self::Tip => 'Tip jar',
            self::Qa => 'Paid question',
        };
    }

    /**
     * A tip's amount comes from the buyer, so its `price_minor` is a suggestion, not the charge.
     */
    public function buyerSetsAmount(): bool
    {
        return $this === self::Tip;
    }

    /**
     * Only a paid question holds the money until the creator delivers (manual capture).
     */
    public function isAuthorisedThenCaptured(): bool
    {
        return $this === self::Qa;
    }

    /**
     * The buyer writes something at checkout (a tip message, the question itself).
     */
    public function takesBuyerMessage(): bool
    {
        return $this === self::Tip || $this === self::Qa;
    }
}
