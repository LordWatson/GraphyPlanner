<?php

namespace App\Services\AI;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

/**
 * Step M-7.1 (improvements I-9.1) — the single source of truth for "may this org/user use AI?".
 *
 * Before this, `AiController`, `ClientController`/`BuildClientPageHeaderAction` and
 * `PostController::edit` each repeated `config('ai.enabled') && role ∈ {Owner, Strategist}` inline,
 * and none honoured a per-org switch. The effective enablement is now:
 *
 *   config('ai.enabled')  — the platform/environment flag (a vendor key must also be set for a real
 *                           provider to bind; see AppServiceProvider), AND
 *   organization.ai_enabled — the Owner's per-org toggle (Settings → AI usage).
 *
 * Turning off either one immediately hides every AI surface for that org (dev plan Step 2.13).
 * Using AI (as opposed to merely having it enabled) is additionally Owner/Strategist-only.
 */
class AiAccess
{
    /**
     * @var array<int, Role>
     */
    private const USER_ROLES = [Role::Owner, Role::Strategist];

    /**
     * Is AI switched on for this organization at all? (Platform flag AND the org's own toggle.)
     */
    public function enabledForOrg(?Organization $organization): bool
    {
        return (bool) config('ai.enabled', false)
            && $organization !== null
            && $organization->ai_enabled;
    }

    /**
     * Is AI switched on for the organization this user belongs to?
     */
    public function enabledForUser(?User $user): bool
    {
        return $user !== null && $this->enabledForOrg($user->organization);
    }

    /**
     * May this user actually invoke AI? Enabled for their org AND a producing role.
     */
    public function canUse(?User $user): bool
    {
        return $this->enabledForUser($user)
            && $user !== null
            && in_array($user->role, self::USER_ROLES, true);
    }

    /**
     * Controller guard for an AI endpoint owned by this user's org: a disabled org is a 404 (the
     * feature does not exist for them), a wrong role is a 403. Resource-level ownership stays with
     * the usual `$this->authorize('view', $resource)` policy call in the controller.
     */
    public function authorize(?User $user): void
    {
        abort_unless($this->enabledForUser($user), 404);
        abort_unless(
            $user !== null && in_array($user->role, self::USER_ROLES, true),
            403,
        );
    }
}
