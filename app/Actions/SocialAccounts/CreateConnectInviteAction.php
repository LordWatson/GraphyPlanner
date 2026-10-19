<?php

namespace App\Actions\SocialAccounts;

use App\Mail\ClientConnectInviteMail;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Step M-2.8 — an Owner/Strategist creates a self-connect link for one client, optionally limited
 * to specific account slots or platforms. Only the token's hash is stored; the raw token is
 * returned once (for the copy-link screen) and emailed once when an address is given. Neither is
 * ever logged.
 */
class CreateConnectInviteAction
{
    /**
     * @param  array{email?: string|null, social_account_ids?: list<int>|null, platforms?: list<string>|null, expires_in_days?: int|null}  $data
     * @return array{invite: ConnectInvite, token: string, url: string}
     */
    public function __invoke(Client $client, User $creator, array $data): array
    {
        $token = Str::random(64);

        $invite = DB::transaction(fn () => ConnectInvite::query()->create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'token_hash' => ConnectInvite::hashToken($token),
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'allowed_social_account_ids' => ($data['social_account_ids'] ?? []) === [] ? null : array_map('intval', (array) $data['social_account_ids']),
            'platforms' => ($data['platforms'] ?? []) === [] ? null : (array) $data['platforms'],
            'expires_at' => now()->addDays((int) ($data['expires_in_days'] ?? ConnectInvite::DEFAULT_TTL_DAYS)),
            'created_by' => $creator->id,
        ]));

        $url = route('connect.show', $token);

        if ($invite->email !== null) {
            Mail::to($invite->email)->send(new ClientConnectInviteMail($invite, $url, (string) $client->organization?->name));
        }

        Log::info('Connect invite created', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'connect_invite_id' => $invite->id,
            'user_id' => $creator->id,
            'emailed' => $invite->email !== null,
            'limited' => $invite->allowed_social_account_ids !== null || $invite->platforms !== null,
        ]);

        return ['invite' => $invite, 'token' => $token, 'url' => $url];
    }
}
