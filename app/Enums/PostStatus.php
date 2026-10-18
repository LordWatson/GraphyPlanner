<?php

namespace App\Enums;

enum PostStatus: string
{
    case Idea = 'idea';
    case Draft = 'draft';
    case InternalReview = 'internal_review';
    case WaitingClient = 'waiting_client';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    // Step I-1.3: some targets went out, at least one failed — "Retry failed targets" resends
    // only the failed ones.
    case PartiallyPublished = 'partially_published';
    case Failed = 'failed';
    case Archived = 'archived';

    /**
     * Statuses a client portal contact (`Role::ClientReviewer`) may ever see. Pre-review internal
     * stages (idea, draft, internal review) never reach the portal — every portal surface (posts,
     * campaigns, calendar, post detail) filters through this one list.
     *
     * @return list<self>
     */
    public static function clientVisible(): array
    {
        return [
            self::WaitingClient,
            self::ChangesRequested,
            self::Approved,
            self::Scheduled,
            self::Publishing,
            self::Published,
            self::PartiallyPublished,
            self::Failed,
            self::Archived,
        ];
    }

    /**
     * Step C-0.4: work that still needs a decision before it can go out — campaign health and
     * readiness count these as "not yet approved".
     *
     * @return list<self>
     */
    public static function preApproval(): array
    {
        return [self::Idea, self::Draft, self::InternalReview, self::WaitingClient, self::ChangesRequested];
    }

    /**
     * Step C-4.5: signed off and on its way (or out). Failed posts need attention, so they don't
     * count; archived posts are dropped work.
     *
     * @return list<self>
     */
    public static function approvedOrLater(): array
    {
        return [self::Approved, self::Scheduled, self::Publishing, self::Published, self::PartiallyPublished];
    }

    public function isClientVisible(): bool
    {
        return in_array($this, self::clientVisible(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Draft => 'Draft',
            self::InternalReview => 'Internal review',
            self::WaitingClient => 'Waiting on client',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PartiallyPublished => 'Partially published',
            self::Failed => 'Failed',
            self::Archived => 'Archived',
        };
    }
}
