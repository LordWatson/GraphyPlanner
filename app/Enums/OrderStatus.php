<?php

namespace App\Enums;

/**
 * Step CG-4.2 — an order's lifecycle. The map is the authority: `TransitionOrderStatusAction`
 * refuses anything not listed here, so a late or replayed webhook can never walk an order
 * backwards (a refunded order going back to `paid`, a cancelled one to `fulfilled`).
 *
 *     pending → paid → fulfilled → refunded
 *             ↘ cancelled        ↗
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Fulfilled = 'fulfilled';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Fulfilled => 'Delivered',
            self::Refunded => 'Refunded',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Fulfilled, self::Refunded, self::Cancelled],
            self::Fulfilled => [self::Refunded],
            self::Refunded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * The money has been taken (or authorised and captured) — a download link may be issued.
     */
    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::Fulfilled;
    }
}
