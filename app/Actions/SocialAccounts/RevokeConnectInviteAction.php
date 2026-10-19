<?php

namespace App\Actions\SocialAccounts;

use App\Models\ConnectInvite;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-2.8 — kill a self-connect link straight away. Accounts already connected through it
 * stay connected.
 */
class RevokeConnectInviteAction
{
    public function __invoke(ConnectInvite $invite, User $user): ConnectInvite
    {
        if ($invite->revoked_at === null) {
            $invite->update(['revoked_at' => now()]);
        }

        Log::info('Connect invite revoked', [
            'org_id' => $invite->org_id,
            'client_id' => $invite->client_id,
            'connect_invite_id' => $invite->id,
            'user_id' => $user->id,
        ]);

        return $invite;
    }
}
