<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step 15.3 — permanently deletes an organization. Every org-scoped table cascades from
 * `organizations` (`cascadeOnDelete()`), but `users.org_id` is `nullOnDelete()` — left alone,
 * the org's members would survive as org-less accounts that can still sign in. So members are
 * deleted explicitly first (with their notifications and sessions), all in one transaction.
 */
class DeleteOrganizationAction
{
    public function __invoke(Organization $organization, User $actor): void
    {
        if ($organization->isPreviewFixture()) {
            throw ValidationException::withMessages([
                'organization' => __('The preview fixture organization can’t be deleted.'),
            ]);
        }

        $orgId = $organization->id;

        $memberCount = DB::transaction(function () use ($organization) {
            $memberIds = $organization->users()->pluck('id');

            DB::table('notifications')
                ->where('notifiable_type', (new User)->getMorphClass())
                ->whereIn('notifiable_id', $memberIds)
                ->delete();

            DB::table('sessions')->whereIn('user_id', $memberIds)->delete();

            User::query()->whereIn('id', $memberIds)->delete();

            $organization->delete();

            return $memberIds->count();
        });

        Log::warning('Organization deleted', [
            'org_id' => $orgId,
            'user_id' => $actor->id,
            'members_deleted' => $memberCount,
        ]);
    }
}
