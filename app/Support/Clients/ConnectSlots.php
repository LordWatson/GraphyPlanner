<?php

namespace App\Support\Clients;

use App\Enums\ConnectionStatus;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformRolloutGate;
use Illuminate\Support\Collection;

/**
 * Step M-2.8 — the account slots a client sees on the self-connect page and the portal's "Connect
 * your accounts" panel. Deliberately minimal: platform, handle and whether it's connected —
 * nothing else about the account (no ids beyond what the connect button needs, no tokens, no
 * vendor ids, no posting data).
 */
final class ConnectSlots
{
    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array{id: int, platform: string, platform_label: string, handle: string, connected: bool, needs_reconnect: bool}>
     */
    public static function forList(Collection $accounts): array
    {
        return array_values($accounts
            // A platform that isn't switched on for publishing can't be connected yet.
            ->filter(fn (SocialAccount $account) => app(PlatformRolloutGate::class)->platformEnabled($account->platform))
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'connected' => $account->connection_status === ConnectionStatus::Connected,
                'needs_reconnect' => $account->connection_status === ConnectionStatus::TokenExpired,
            ])
            ->all());
    }
}
