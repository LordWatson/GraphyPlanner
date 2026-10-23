<?php

namespace App\Enums;

/**
 * Step M-7.1 (dev plan Step 2.9) — how a user rated one AI suggestion. `Up`/`Down` are the explicit
 * thumbs; `Applied` is the implicit positive signal recorded when a suggestion is actually used, so
 * acceptance rate can be measured without requiring an explicit click.
 */
enum AiRating: string
{
    case Up = 'up';
    case Down = 'down';
    case Applied = 'applied';

    public function isPositive(): bool
    {
        return $this === self::Up || $this === self::Applied;
    }

    public function label(): string
    {
        return match ($this) {
            self::Up => 'Helpful',
            self::Down => 'Not helpful',
            self::Applied => 'Used',
        };
    }
}
