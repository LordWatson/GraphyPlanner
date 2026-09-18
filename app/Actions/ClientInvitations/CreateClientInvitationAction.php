<?php

namespace App\Actions\ClientInvitations;

use App\Mail\ClientInvitationMail;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class CreateClientInvitationAction
{
    /**
     * Create a client portal invitation and email the invited contact (via Resend) a signed
     * accept-invite link (Step 6.1). Hashed at rest per spec §10 — the plain token only ever
     * exists in the signed URL emailed below.
     */
    public function __invoke(Client $client, string $email, User $invitedBy): ClientInvitation
    {
        $invitation = DB::transaction(function () use ($client, $email, $invitedBy) {
            $plainToken = Str::random(64);

            return ClientInvitation::create([
                'client_id' => $client->id,
                'email' => $email,
                'invited_by_user_id' => $invitedBy->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addDays(7),
            ])->setAttribute('plain_token', $plainToken);
        });

        $acceptUrl = URL::to("/client-invite/{$invitation->getAttribute('plain_token')}");

        try {
            Mail::to($email)->send(new ClientInvitationMail($invitation, $acceptUrl));

            Log::info('Client invitation sent', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'invited_email' => $email,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to send client invitation email', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'invited_email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return $invitation;
    }
}
