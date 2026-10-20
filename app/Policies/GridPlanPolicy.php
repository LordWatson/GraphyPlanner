<?php

namespace App\Policies;

use App\Actions\Grids\GridPlanStatusTransitionMap;
use App\Enums\GridPlanStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\User;

/**
 * Phase 18 — who may see and change a grid plan. Staff are scoped to their organization; a
 * `ClientReviewer` only ever sees their own client's plans, and only once a plan has been sent to
 * them (`GridPlanStatus::clientVisible()`). Viewers read only.
 */
class GridPlanPolicy
{
    /** Roles that build and move grid plans. */
    private const EDITORS = [Role::Owner, Role::Strategist, Role::Designer];

    /** Staff roles that may read grid plans. */
    private const READERS = [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer];

    /**
     * The client's grid list (the client page's Grid tab, the portal's grids).
     */
    public function viewAny(User $user, Client $client): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $client->id;
        }

        return $user->org_id === $client->org_id && in_array($user->role, self::READERS, true);
    }

    /**
     * The org-wide Grids hub (`GET /grids`): staff only — client contacts use the portal.
     */
    public function viewHub(User $user): bool
    {
        return $user->org_id !== null && in_array($user->role, self::READERS, true);
    }

    public function view(User $user, GridPlan $plan): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $plan->client_id && $plan->status->isClientVisible();
        }

        return $user->org_id === $plan->org_id && in_array($user->role, self::READERS, true);
    }

    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && in_array($user->role, self::EDITORS, true);
    }

    /**
     * Change the layout, tiles or plan settings. Locked while the client is looking at it
     * (`waiting_client`, like a post out for review) and while archived (restore it first).
     */
    public function update(User $user, GridPlan $plan): bool
    {
        if (in_array($plan->status, [GridPlanStatus::WaitingClient, GridPlanStatus::Archived], true)) {
            return false;
        }

        return $user->org_id === $plan->org_id && in_array($user->role, self::EDITORS, true);
    }

    /**
     * Move the plan to `$to`. A client reviewer may only decide (approve / request changes) on
     * their own client's plan while it's waiting on them.
     */
    public function transition(User $user, GridPlan $plan, GridPlanStatus $to): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $plan->client_id
                && $plan->status === GridPlanStatus::WaitingClient
                && in_array($to, [GridPlanStatus::Approved, GridPlanStatus::ChangesRequested], true);
        }

        return $user->org_id === $plan->org_id && in_array($user->role, self::EDITORS, true);
    }

    /**
     * Approve a plan whose client must sign off, without the client — Owners only, with a reason
     * (same rule as `PostPolicy::overrideApproval`).
     */
    public function overrideApproval(User $user, GridPlan $plan): bool
    {
        return $user->role === Role::Owner
            && $user->org_id === $plan->org_id
            && GridPlanStatusTransitionMap::needsClientApproval($plan, $plan->status, GridPlanStatus::Approved);
    }

    /**
     * Turn tiles into posts and schedule the sequence (Steps 18.9–18.11) — the same people who can
     * create posts for the client.
     */
    public function produce(User $user, GridPlan $plan): bool
    {
        return $user->org_id === $plan->org_id && in_array($user->role, self::EDITORS, true);
    }

    /**
     * Step 18.12 — the grid's AI help: Owners and Strategists, only while AI is switched on (the
     * same rule as every other AI surface).
     */
    public function useAi(User $user, GridPlan $plan): bool
    {
        return (bool) config('ai.enabled', false)
            && $user->org_id === $plan->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function delete(User $user, GridPlan $plan): bool
    {
        return $user->org_id === $plan->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function comment(User $user, GridPlan $plan): bool
    {
        return $this->view($user, $plan);
    }
}
