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
