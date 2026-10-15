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
            self::Failed,
            self::Archived,
        ];
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
            self::Failed => 'Failed',
            self::Archived => 'Archived',
        };
    }
}
