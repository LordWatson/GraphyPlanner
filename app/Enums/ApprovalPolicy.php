<?php

namespace App\Enums;

/**
 * Step M-6.3 — how a client's posts are approved when they have more than one approver contact.
 * `Any` is the default and matches the original single-approver behaviour (one approval is enough).
 */
enum ApprovalPolicy: string
{
    /** Any one approver's approval releases the post. */
    case Any = 'any';

    /** Every approver must approve before the post is released. */
    case All = 'all';

    /** Approvers approve in order (e.g. Brand Manager → Legal); the last one releases the post. */
    case Sequential = 'sequential';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any one approver',
            self::All => 'All approvers',
            self::Sequential => 'In sequence',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Any => 'A single approver can sign off a post.',
            self::All => 'Every approver must sign off before a post is approved.',
            self::Sequential => 'Approvers sign off in order; the last one releases the post.',
        };
    }
}
