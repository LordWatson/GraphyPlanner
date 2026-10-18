<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;

/**
 * Campaign abilities, following the role table in `.junie/plans/campaigns-enhancement.md` §1.4
 * (recorded in `.junie/modules/campaigns.md`).
 */
class CampaignPolicy
{
    /**
     * Determine whether the user can view the campaigns for a client.
     */
    public function viewAny(User $user, Client $client): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $client->id;
        }

        return $user->org_id === $client->org_id && $this->isStaff($user);
    }

    /**
     * Step C-1.1: the org-wide campaigns hub (staff only; client contacts use the portal).
     */
    public function viewHub(User $user): bool
    {
        return $user->org_id !== null && $this->isStaff($user);
    }

    /**
     * Determine whether the user can view a specific campaign.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->viewAny($user, $campaign->client);
    }

    /**
     * Determine whether the user can create a campaign for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && $this->isPlanner($user);
    }

    /**
     * Edit the campaign, its brief, phases and plan.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id && $this->isPlanner($user);
    }

    /**
     * Step C-0.3: change status (launch, pause, complete, archive…).
     */
    public function transition(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    /**
     * Determine whether the user can delete a campaign.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id && $this->isPlanner($user);
    }

    /**
     * Step C-3.2: start a post from a planned deliverable; Step C-4.1: manage the asset kit.
     * Designers produce, so they can do both.
     */
    public function produce(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Step C-8.1: fee, budget, invoices and profitability — Phase 17's revenue rule. Never a
     * client contact, never a Designer or Viewer.
     */
    public function viewBilling(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id && $this->isPlanner($user);
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer], true);
    }

    private function isPlanner(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
