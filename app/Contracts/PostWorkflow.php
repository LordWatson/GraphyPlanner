<?php

namespace App\Contracts;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;

/**
 * Step B-3.1 — the post status workflow for one workspace type. Agencies and business/creator
 * workspaces run the *same* state machine engine (`PostStatusTransitionMap`) but expose different
 * legal moves: an agency has a client-approval stage; a solo business owner self-approves (and can
 * opt a small team into an internal review step). Every per-type difference in the lifecycle lives
 * behind this contract, resolved per org type by `App\Services\Posts\PostWorkflowResolver`, rather
 * than as `if ($org->isBusiness())` branches scattered through the Action/controller layer.
 *
 * `PostStatusTransitionMap` remains the single source of truth for which raw edges exist; a
 * workflow only ever *narrows* that map for its workspace type, never invents an edge the map
 * doesn't allow.
 */
interface PostWorkflow
{
    /**
     * The statuses `$post` may legally move to for this workspace, from its current status.
     * Callers still authorize *who* may move it (via `PostPolicy::transition`); this answers only
     * *what* the workflow permits. `$user` is accepted for workflows that vary the move set by
     * role, and is null for the unauthenticated review portal.
     *
     * @return list<PostStatus>
     */
    public function allowedTransitions(Post $post, ?User $user = null): array;

    /**
     * The approval mode a freshly created post should take in this workspace. Agencies default to
     * client approval; a business workspace has no client, so posts are internal-only.
     */
    public function defaultApprovalMode(): ApprovalMode;

    /**
     * Whether a post must pass through a review step before it can be approved/scheduled in this
     * organization. Drives the "Send for review" vs. "Schedule now" branch in
     * `QuickSchedulePostAction` and the editor's primary button.
     */
    public function requiresReview(Organization $organization): bool;
}
