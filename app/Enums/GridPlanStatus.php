<?php

namespace App\Enums;

/**
 * Phase 18 — a grid plan's approval stage. Only the approval stages are stored; production
 * progress ("4 of 9 posted · 3 scheduled") is derived from the linked posts, never stored, so it
 * can't drift. Same values as the post statuses of the same name, so a grid and a post in
 * "Waiting on client" read identically.
 */
enum GridPlanStatus: string
{
    case Draft = 'draft';
    case InternalReview = 'internal_review';
    case WaitingClient = 'waiting_client';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * Statuses a client portal contact may see (mirrors `PostStatus::clientVisible()`): drafts and
     * internal review never reach the portal or a review link.
     *
     * @return list<self>
     */
    public static function clientVisible(): array
    {
        return [self::WaitingClient, self::ChangesRequested, self::Approved, self::Archived];
    }

    public function isClientVisible(): bool
    {
        return in_array($this, self::clientVisible(), true);
    }

    /**
     * Statuses whose edit reopens the plan to `draft` (Step 18.4) — the look was signed off, or
     * the client asked for changes, so any change needs a fresh review.
     */
    public function reopensOnEdit(): bool
    {
        return in_array($this, [self::Approved, self::ChangesRequested], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InternalReview => 'Internal review',
            self::WaitingClient => 'Waiting on client',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Archived => 'Archived',
        };
    }
}
