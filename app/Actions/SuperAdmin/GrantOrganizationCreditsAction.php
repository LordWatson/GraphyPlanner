<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-2.6 / B-9.4 — a super admin grants an org a temporary pool of extra AI text credits that
 * tops up its monthly plan allowance (optionally expiring after N days). Audited.
 */
class GrantOrganizationCreditsAction
{
    public function __invoke(Organization $organization, int $credits, ?int $expiresInDays, User $actor, ?string $note = null): Organization
    {
        return DB::transaction(function () use ($organization, $credits, $expiresInDays, $actor, $note) {
            $organization->bonus_ai_text_credits = max(0, $credits);
            $organization->bonus_ai_credits_expires_at = $expiresInDays !== null
                ? Carbon::now()->addDays($expiresInDays)
                : null;
            $organization->save();

            PlanAuditLog::create([
                'org_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => PlanAuditLog::ACTION_CREDITS_GRANTED,
                'note' => $note,
                'meta' => ['credits' => max(0, $credits), 'expires_in_days' => $expiresInDays],
            ]);

            Log::info('Super admin granted AI credits', [
                'org_id' => $organization->id,
                'user_id' => $actor->id,
                'credits' => max(0, $credits),
                'expires_in_days' => $expiresInDays,
            ]);

            return $organization;
        });
    }
}
