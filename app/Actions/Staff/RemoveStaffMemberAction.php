<?php

namespace App\Actions\Staff;

use App\Models\StaffActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemoveStaffMemberAction
{
    /**
     * Remove a staff member from the organization, logging the removal before the user record
     * itself is deleted (the log entry's `user_id` is nulled by the FK afterwards, but `note`
     * keeps the member's name/email for the audit trail).
     */
    public function __invoke(User $staff, User $actor): void
    {
        DB::transaction(function () use ($staff, $actor) {
            StaffActivityLog::create([
                'org_id' => $staff->org_id,
                'user_id' => $staff->id,
                'actor_user_id' => $actor->id,
                'action' => 'removed',
                'from_role' => $staff->role,
                'to_role' => null,
                'note' => "{$staff->name} ({$staff->email}) was removed from the organization.",
            ]);

            Log::info('Staff member removed', [
                'org_id' => $staff->org_id,
                'user_id' => $staff->id,
                'actor_user_id' => $actor->id,
            ]);

            $staff->delete();
        });
    }
}
