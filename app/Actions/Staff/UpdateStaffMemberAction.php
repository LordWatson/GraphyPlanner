<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\StaffActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateStaffMemberAction
{
    /**
     * Update a staff member's name/role and log the change (a role change gets its own
     * `StaffActivityLog` entry so Owners have an audit trail of who changed what).
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $staff, array $data, User $actor): User
    {
        return DB::transaction(function () use ($staff, $data, $actor) {
            $fromRole = $staff->role;
            $toRole = isset($data['role']) ? Role::from($data['role']) : $fromRole;

            $staff->update([
                'name' => $data['name'] ?? $staff->name,
                'role' => $toRole,
            ]);

            if ($fromRole !== $toRole) {
                StaffActivityLog::create([
                    'org_id' => $staff->org_id,
                    'user_id' => $staff->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'role_changed',
                    'from_role' => $fromRole,
                    'to_role' => $toRole,
                ]);

                Log::info('Staff member role changed', [
                    'org_id' => $staff->org_id,
                    'user_id' => $staff->id,
                    'actor_user_id' => $actor->id,
                    'from_role' => $fromRole?->value,
                    'to_role' => $toRole->value,
                ]);
            }

            return $staff;
        });
    }
}
