<?php

namespace App\Enums;

/**
 * Step CG-3.1 — the kinds of block a bio page can hold. Only {@see self::live()} types are
 * buildable: `email_capture` arrives with the audience phase (CG-6) and is rejected by validation
 * until then, so a page can never hold a block nothing renders.
 *
 * Step CG-4.2 switched `product` on. There is no separate `booking` block — a booking is a product
 * type, so it is sold through a `product` block like everything else in the store.
 */
enum BioBlockType: string
{
    case Link = 'link';
    case LatestPosts = 'latest_posts';
    case MediaKit = 'media_kit';
    case Product = 'product';
    case EmailCapture = 'email_capture';
    case Booking = 'booking';

    public function label(): string
    {
        return match ($this) {
            self::Link => 'Link',
            self::LatestPosts => 'Latest posts',
            self::MediaKit => 'Media kit',
            self::Product => 'Product',
            self::EmailCapture => 'Email sign-up',
            self::Booking => 'Booking',
        };
    }

    /**
     * The types a page can be saved with today.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Link, self::LatestPosts, self::MediaKit, self::Product];
    }

    /**
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return array_map(fn (self $type) => $type->value, self::live());
    }
}
