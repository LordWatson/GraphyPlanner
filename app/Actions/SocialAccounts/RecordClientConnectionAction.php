<?php

namespace App\Actions\SocialAccounts;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\ConnectInvite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ClientConnectedAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-2.8 — after a client connects an account themselves (through a self-connect link, or the
 * portal's "Connect your accounts" panel): record it on the invite, and tell the agency — the
 * client's owner, or the org's Owners/Strategists when it has none. The client's health refreshes
 * on its own (the account's connection status changed, `SocialAccount::booted()`), so an
 * expired-token Red clears.
 */
class RecordClientConnectionAction
{
    public function __invoke(SocialAccount $account, ?ConnectInvite $invite, ?User $byClientUser = null): void
    {
        $account->refresh();

        if ($account->connection_status !== ConnectionStatus::Connected) {
            return;
        }

        if ($invite !== null) {
            DB::transaction(function () use ($invite, $account) {
                /** @var ConnectInvite $locked */
                $locked = ConnectInvite::query()->withoutOrgScope()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
                $locked->update([
                    'used_at' => $locked->used_at ?? now(),
                    'connected_account_ids' => array_values(array_unique([...($locked->connected_account_ids ?? []), $account->id])),
                ]);
            });
        }

        $owner = $account->client?->owner_user_id ? User::query()->find($account->client->owner_user_id) : null;
        $recipients = $owner !== null
            ? collect([$owner])
            : User::query()->where('org_id', $account->org_id)->whereIn('role', [Role::Owner, Role::Strategist])->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new ClientConnectedAccount($account, $byClientUser?->name));
        }

        Log::info('Client connected a social account', [
            'org_id' => $account->org_id,
            'client_id' => $account->client_id,
            'social_account_id' => $account->id,
            'connect_invite_id' => $invite?->id,
            'via' => $invite !== null ? 'invite' : 'portal',
        ]);
    }
}
