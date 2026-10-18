<?php

namespace App\Enums;

/**
 * Step C-3.2 — a planned deliverable's state, derived from its linked post (never stored). The
 * order of the cases is the order work moves through.
 */
enum DeliverableState: string
{
    case NotStarted = 'not_started';
    case InProduction = 'in_production';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Dropped = 'dropped';

    public static function fromPost(?PostStatus $status): self
    {
        return match ($status) {
            null => self::NotStarted,
            PostStatus::Idea, PostStatus::Draft, PostStatus::ChangesRequested => self::InProduction,
            PostStatus::InternalReview, PostStatus::WaitingClient => self::InReview,
            PostStatus::Approved => self::Approved,
            PostStatus::Scheduled, PostStatus::Publishing => self::Scheduled,
            PostStatus::Published, PostStatus::PartiallyPublished => self::Published,
            PostStatus::Failed => self::Failed,
            PostStatus::Archived => self::Dropped,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProduction => 'In production',
            self::InReview => 'In review',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Failed => 'Failed',
            self::Dropped => 'Dropped',
        };
    }

    /**
     * Approved or further along — what readiness counts as done.
     */
    public function isReady(): bool
    {
        return in_array($this, [self::Approved, self::Scheduled, self::Published], true);
    }

    public function isStarted(): bool
    {
        return $this !== self::NotStarted;
    }
}
