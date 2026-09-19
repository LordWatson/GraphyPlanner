<?php

namespace App\Actions\StaffInvitations;

use App\Models\StaffActivityLog;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcceptStaffInvitationAction
{
    /**
     * Accept a pending staff invitation: creates (or, if the invited email already has a user
     * account, reuses) a `User` scoped to the invitation's org with the invited role, sets the
     * chosen password, and marks the invitation accepted. Wrapped in `DB::transaction()` since it
     * touches both the user and the invitation record.
     */
    public function __invoke(StaffInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $organization = $invitation->organization;

            $user = User::query()->where('email', $invitation->email)->first();

            if ($user) {
                $user->update([
                    'name' => $name,
                    'password' => $password,
                    'org_id' => $organization->id,
                    'role' => $invitation->role,
                    'client_id' => null,
                ]);
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => $password,
                    'org_id' => $organization->id,
                    'role' => $invitation->role,
                ]);
            }

            $invitation->update(['accepted_at' => now()]);

            StaffActivityLog::create([
                'org_id' => $organization->id,
                'user_id' => $user->id,
                'actor_user_id' => $user->id,
                'action' => 'joined',
                'to_role' => $invitation->role,
                'note' => "{$user->name} accepted the invitation and joined as {$invitation->role->label()}.",
            ]);

            Log::info('Staff invitation accepted', [
                'org_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $invitation->role->value,
            ]);

            return $user;
        });
    }
}
