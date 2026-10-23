<?php

namespace App\Enums;

/**
 * Step B-2.1 — what a business workspace's owner picks on the `/start` signup: "I'm a…". A
 * `business` runs a shop/salon/gym and posts for it; a `creator` is an influencer posting across
 * their own channels. Stored on the primary brand (`clients.brand_kind`) and used to tune copy,
 * default ideas (local/seasonal vs. creator cadence) and, later, plan defaults. Never shown with
 * agency vocabulary.
 */
enum BrandKind: string
{
    case Business = 'business';
    case Creator = 'creator';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Creator => 'Creator',
        };
    }

    /**
     * The one-line, second-person description shown next to the choice on the signup card.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::Business => 'A shop, salon, studio or service — posting for your own brand.',
            self::Creator => 'An influencer or creator posting across your own channels.',
        };
    }
}
