<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\StaffActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

            // Defence in depth behind `UpdateStaffMemberRequest`: never let a tenant flow grant a
            // role the workspace can't assign — above all `SuperAdmin` (org-less platform staff),
            // which would hand the member platform god-mode. Changing nothing (same role) is fine.
            if ($toRole !== $fromRole && ! in_array($toRole, Role::assignableFor($staff->organization->type), true)) {
                throw new InvalidArgumentException("Role [{$toRole->value}] can't be assigned in a {$staff->organization->type->value} workspace.");
            }

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
