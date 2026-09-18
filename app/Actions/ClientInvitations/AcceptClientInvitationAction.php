<?php

namespace App\Actions\ClientInvitations;

use App\Enums\Role;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcceptClientInvitationAction
{
    /**
     * Accept a pending client portal invitation (Step 6.2): creates (or, if the invited email
     * already has a `Role::ClientReviewer` user, reuses) a `User` scoped to the invitation's
     * client/org, sets the chosen password, and marks the invitation accepted. Wrapped in
     * `DB::transaction()` since it touches both the user and the invitation record.
     */
    public function __invoke(ClientInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $client = $invitation->client;

            $user = User::query()
                ->where('email', $invitation->email)
                ->where('role', Role::ClientReviewer)
                ->first();

            if ($user) {
                $user->update([
                    'name' => $name,
                    'password' => $password,
                    'org_id' => $client->org_id,
                    'client_id' => $client->id,
                ]);
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $invitation->email,
                    'password' => $password,
                    'org_id' => $client->org_id,
                    'role' => Role::ClientReviewer,
                    'client_id' => $client->id,
                ]);
            }

            $invitation->update(['accepted_at' => now()]);

            Log::info('Client invitation accepted', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'user_id' => $user->id,
            ]);

            return $user;
        });
    }
}
