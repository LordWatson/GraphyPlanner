<?php

namespace App\Actions\Grids;

use App\Enums\ApprovalMode;
use App\Enums\GridPlanStatus;
use App\Models\GridPlan;

/**
 * Step 18.7 — the explicit `GridPlan::status` state machine, one map like
 * `PostStatusTransitionMap` so every legal move is visible at a glance. Production progress
 * ("In production", "Live") is derived from the linked posts, so it never appears here.
 */
class GridPlanStatusTransitionMap
{
    /**
     * @var array<string, list<string>>
     */
    public const MAP = [
        GridPlanStatus::Draft->value => [
            GridPlanStatus::InternalReview->value,
            GridPlanStatus::Archived->value,
        ],
        GridPlanStatus::InternalReview->value => [
            GridPlanStatus::Draft->value,
            GridPlanStatus::WaitingClient->value,
            GridPlanStatus::Approved->value,
        ],
        GridPlanStatus::WaitingClient->value => [
            GridPlanStatus::Approved->value,
            GridPlanStatus::ChangesRequested->value,
        ],
        GridPlanStatus::ChangesRequested->value => [
            GridPlanStatus::Draft->value,
            GridPlanStatus::InternalReview->value,
        ],
        GridPlanStatus::Approved->value => [
            GridPlanStatus::Draft->value,
            GridPlanStatus::Archived->value,
        ],
        GridPlanStatus::Archived->value => [
            GridPlanStatus::Draft->value,
        ],
    ];

    public static function isAllowed(GridPlanStatus $from, GridPlanStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value], true);
    }

    /**
     * The move for this plan: with a client whose default approval mode is `client_required`,
     * `internal_review → approved` is blocked — only through the client, or an Owner override
     * with a reason (`TransitionGridPlanStatusAction::overrideApproval()`).
     */
    public static function isAllowedFor(GridPlan $plan, GridPlanStatus $to, ?GridPlanStatus $from = null): bool
    {
        $from ??= $plan->status;

        return self::isAllowed($from, $to) && ! self::needsClientApproval($plan, $from, $to);
    }

    public static function needsClientApproval(GridPlan $plan, GridPlanStatus $from, GridPlanStatus $to): bool
    {
        return $from === GridPlanStatus::InternalReview
            && $to === GridPlanStatus::Approved
            && self::approvalMode($plan) === ApprovalMode::ClientRequired;
    }

    /**
     * @return list<GridPlanStatus>
     */
    public static function allowedFor(GridPlan $plan): array
    {
        return array_values(array_filter(
            self::allowedFrom($plan->status),
            fn (GridPlanStatus $to) => self::isAllowedFor($plan, $to),
        ));
    }

    /**
     * @return list<GridPlanStatus>
     */
    public static function allowedFrom(GridPlanStatus $from): array
    {
        return array_map(
            static fn (string $value): GridPlanStatus => GridPlanStatus::from($value),
            self::MAP[$from->value],
        );
    }

    /**
     * Moves that go forward through the workflow (the primary next step in the planner); the rest
     * (back to draft, archive) sit under "More".
     */
    public static function isForward(GridPlanStatus $from, GridPlanStatus $to): bool
    {
        return match ($from) {
            GridPlanStatus::Draft => $to === GridPlanStatus::InternalReview,
            GridPlanStatus::InternalReview => in_array($to, [GridPlanStatus::WaitingClient, GridPlanStatus::Approved], true),
            GridPlanStatus::WaitingClient => $to === GridPlanStatus::Approved,
            GridPlanStatus::ChangesRequested => $to === GridPlanStatus::InternalReview,
            GridPlanStatus::Archived => $to === GridPlanStatus::Draft,
            GridPlanStatus::Approved => false,
        };
    }

    private static function approvalMode(GridPlan $plan): ApprovalMode
    {
        $plan->loadMissing('client');

        return $plan->client->default_approval_mode ?? ApprovalMode::ClientRequired;
    }
}
