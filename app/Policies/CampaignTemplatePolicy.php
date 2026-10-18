<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CampaignTemplate;
use App\Models\User;

/**
 * Step C-3.4 — templates are the studio's own playbook: Owners and Strategists manage and use
 * them (plan §1.4); they never cross organizations.
 */
class CampaignTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->org_id !== null && $this->isPlanner($user);
    }

    public function view(User $user, CampaignTemplate $template): bool
    {
        return $user->org_id === $template->org_id && $this->isPlanner($user);
    }

    public function update(User $user, CampaignTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    public function delete(User $user, CampaignTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    private function isPlanner(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
