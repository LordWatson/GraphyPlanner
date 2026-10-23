<?php

namespace App\Services\Posts;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Contracts\PostWorkflow;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;

/**
 * Step B-3.1 — the agency workflow: today's behaviour, unchanged. It wraps `PostStatusTransitionMap`
 * so the full agency lifecycle (idea → draft → internal review → client review → approved →
 * scheduled → live) keeps working byte-for-byte. Introducing the contract must not alter a single
 * agency transition; this class is the proof of that (the regression suite asserts it).
 */
class AgencyPostWorkflow implements PostWorkflow
{
    /**
     * @return list<PostStatus>
     */
    public function allowedTransitions(Post $post, ?User $user = null): array
    {
        // `allowedFor()` already drops the one move an agency can't make unattended — approving a
        // `client_required` post straight from internal review (that's an Owner override). This is
        // exactly what the editor and `TransitionPostStatusAction` consulted before B-3.1.
        return PostStatusTransitionMap::allowedFor($post);
    }

    public function defaultApprovalMode(): ApprovalMode
    {
        return ApprovalMode::ClientRequired;
    }

    /**
     * An agency's lifecycle always has an internal review stage; "require review" is a
     * business-only switch, so this is always true and never consulted for agencies.
     */
    public function requiresReview(Organization $organization): bool
    {
        return true;
    }
}
