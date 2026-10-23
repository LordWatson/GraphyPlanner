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
use InvalidArgumentException;
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
        // Step B-1.5: never invite a role the organization type can't assign (defence in depth
        // behind `StoreStaffInvitationRequest` — e.g. a `SuperAdmin` into a business workspace).
        if (! in_array($role, Role::assignableFor($organization->type), true)) {
            throw new InvalidArgumentException("Role [{$role->value}] can't be assigned in a {$organization->type->value} workspace.");
        }

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
