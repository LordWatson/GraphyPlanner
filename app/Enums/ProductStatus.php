<?php

namespace App\Enums;

/**
 * Step CG-4.2 — whether a product is on sale. Only `live` products appear on the bio page or take
 * a checkout; `archived` keeps the orders history without offering the thing again.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Live => 'On sale',
            self::Archived => 'Archived',
        };
    }

    public function isBuyable(): bool
    {
        return $this === self::Live;
    }
}
