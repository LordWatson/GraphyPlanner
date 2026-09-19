<?php

namespace App\Actions\StaffInvitations;

use App\Enums\Role;
use App\Mail\StaffInvitationMail;
use App\Models\Organization;
use App\Models\StaffActivityLog;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class CreateStaffInvitationAction
{
    /**
     * Create a staff invitation and email the invited person a signed accept-invite link.
     * Hashed at rest per spec §10 — the plain token only ever exists in the signed URL emailed
     * below.
     */
    public function __invoke(Organization $organization, string $email, Role $role, User $invitedBy): StaffInvitation
    {
        $invitation = DB::transaction(function () use ($organization, $email, $role, $invitedBy) {
            $plainToken = Str::random(64);

            $invitation = StaffInvitation::create([
                'org_id' => $organization->id,
                'email' => $email,
                'role' => $role,
                'invited_by_user_id' => $invitedBy->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addDays(7),
            ]);

            StaffActivityLog::create([
                'org_id' => $organization->id,
                'user_id' => null,
                'actor_user_id' => $invitedBy->id,
                'action' => 'invited',
                'to_role' => $role,
                'note' => "Invited {$email} as {$role->label()}.",
            ]);

            return $invitation->setAttribute('plain_token', $plainToken);
        });

        $acceptUrl = URL::to("/staff-invite/{$invitation->getAttribute('plain_token')}");

        try {
            Mail::to($email)->send(new StaffInvitationMail($invitation, $acceptUrl));

            Log::info('Staff invitation sent', [
                'org_id' => $organization->id,
                'invited_email' => $email,
                'role' => $role->value,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to send staff invitation email', [
                'org_id' => $organization->id,
                'invited_email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return $invitation;
    }
}
