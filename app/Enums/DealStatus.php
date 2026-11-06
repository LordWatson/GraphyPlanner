<?php

namespace App\Enums;

/**
 * Step CG-5.2 (B-11.2) — where a brand deal stands. Deliberately **not** a state machine: a deal is
 * a creator's own record of a conversation, and real ones go backwards (agreed, then postponed) or
 * skip steps. The owner sets whichever status is true today; nothing in the app refuses a move.
 *
 * `enquiry` is the one status the app sets by itself, for a deal created by the media kit's enquiry
 * form (CG-5.3) — so an enquiry can never be mistaken for work the creator agreed to.
 */
enum DealStatus: string
{
    case Enquiry = 'enquiry';
    case Pitched = 'pitched';
    case Agreed = 'agreed';
    case Delivered = 'delivered';
    case Paid = 'paid';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => 'Enquiry',
            self::Pitched => 'Pitched',
            self::Agreed => 'Agreed',
            self::Delivered => 'Delivered',
            self::Paid => 'Paid',
            self::Declined => 'Declined',
        };
    }

    /**
     * Still live work: it shows in the open list and its deliverables are worth chasing.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Enquiry, self::Pitched, self::Agreed => true,
            default => false,
        };
    }

    /**
     * Only a deal that actually happened can be named as a past partner on the media kit — and only
     * then if the owner opted it in.
     */
    public function isPastPartner(): bool
    {
        return $this === self::Delivered || $this === self::Paid;
    }

    /**
     * @return list<self>
     */
    public static function selectable(): array
    {
        // `enquiry` is set by the enquiry form, not chosen — but it stays selectable so an owner can
        // move a deal back to it, and so the filter chips can show it.
        return self::cases();
    }
}
