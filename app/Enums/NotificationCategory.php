<?php

namespace App\Enums;

/**
 * Step M-6.7 (I-12.1) — the buckets a notification falls into, which a user sets preferences on.
 * `Other` is the catch-all (always on) so an unmapped notification is never silently dropped.
 */
enum NotificationCategory: string
{
    case Approvals = 'approvals';
    case Comments = 'comments';
    case Publishing = 'publishing';
    case Invoices = 'invoices';
    case Campaigns = 'campaigns';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Approvals => 'Approvals & reviews',
            self::Comments => 'Comments & mentions',
            self::Publishing => 'Publishing',
            self::Invoices => 'Invoices & payments',
            self::Campaigns => 'Campaigns',
            self::Other => 'Everything else',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Approvals => 'A post is approved, has changes requested, or an approval is overdue.',
            self::Comments => 'Someone comments on or mentions you about a post.',
            self::Publishing => 'A post fails to publish or needs attention.',
            self::Invoices => 'An invoice is issued or a payment is reported.',
            self::Campaigns => 'Campaign briefs, deliverables and reviews.',
            self::Other => 'Anything not covered above.',
        };
    }

    /**
     * Map a notification class to its category (short name after the last `\`). Unknown notifications
     * fall into `Other`.
     */
    public static function forNotificationClass(string $class): self
    {
        $name = class_basename($class);

        return match (true) {
            str_contains($name, 'Approval'), str_contains($name, 'WaitingForClient'), str_contains($name, 'WaitingForInternal') => self::Approvals,
            str_contains($name, 'Comment'), str_contains($name, 'Mention'), str_contains($name, 'Inbox') => self::Comments,
            str_contains($name, 'Publish'), str_contains($name, 'TokenExpiring'), str_contains($name, 'OrderAtRisk') => self::Publishing,
            str_contains($name, 'Invoice'), str_contains($name, 'Payment'), str_contains($name, 'Retainer') => self::Invoices,
            str_contains($name, 'Campaign'), str_contains($name, 'Brief'), str_contains($name, 'Deliverable') => self::Campaigns,
            default => self::Other,
        };
    }
}
