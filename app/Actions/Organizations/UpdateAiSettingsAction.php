<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-7.1 (dev plan Steps 2.7–2.8) — the Owner's per-org AI controls: the enable toggle and the
 * monthly token budget (null = unlimited). Kept separate from the image quota so the two Settings
 * sections save independently. Logs the change (never any secret) for an audit trail.
 */
class UpdateAiSettingsAction
{
    public function __invoke(Organization $organization, User $user, bool $enabled, ?int $tokenBudget): Organization
    {
        $previous = [
            'ai_enabled' => $organization->ai_enabled,
            'ai_monthly_token_budget' => $organization->ai_monthly_token_budget,
        ];

        $organization->update([
            'ai_enabled' => $enabled,
            'ai_monthly_token_budget' => $tokenBudget,
        ]);

        Log::info('AI settings updated', [
            'org_id' => $organization->id,
            'user_id' => $user->id,
            'from' => $previous,
            'to' => ['ai_enabled' => $enabled, 'ai_monthly_token_budget' => $tokenBudget],
        ]);

        return $organization;
    }
}
