<?php

namespace App\Actions\SocialAccounts;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step B-5.2 — the business shell's one-tap "log in with…" connect. Given a brand and a platform,
 * it finds (or lazily creates) that brand's single account for the platform with sensible defaults
 * taken from the brand/org, then hands back the vendor's hosted connect URL. The business user
 * never types an API key or a handle — managed credentials and the org's isolated publish profile
 * (Step B-5.1) are resolved under the hood.
 *
 * The find-or-create runs in a transaction; the vendor HTTP call (via `ConnectSocialAccountAction`)
 * runs after it, so a slow or failing vendor never holds a DB transaction open.
 */
class ConnectManagedAccountAction
{
    public function __construct(
        // `ConnectSocialAccountAction` needs the bound `PublishAdapter`, so it's resolved by the
        // container rather than default-constructed here.
        private readonly ConnectSocialAccountAction $connect,
        private readonly CreateSocialAccountAction $create = new CreateSocialAccountAction,
    ) {}

    public function __invoke(Client $brand, Platform $platform, User $user): string
    {
        $account = DB::transaction(function () use ($brand, $platform): SocialAccount {
            $existing = $brand->socialAccounts()->where('platform', $platform)->first();

            if ($existing !== null) {
                return $existing;
            }

            Log::info('Creating managed account for one-tap connect', [
                'client_id' => $brand->id,
                'org_id' => $brand->org_id,
                'platform' => $platform->value,
            ]);

            return ($this->create)($brand, $this->defaults($brand, $platform));
        });

        Log::info('Starting managed account connect', [
            'social_account_id' => $account->id,
            'org_id' => $account->org_id,
            'platform' => $platform->value,
        ]);

        return ($this->connect)($account, $user);
    }

    /**
     * Sensible one-tap defaults from the brand/org — all editable under "Advanced" afterwards.
     *
     * @return array<string, mixed>
     */
    private function defaults(Client $brand, Platform $platform): array
    {
        return [
            'platform' => $platform,
            // A placeholder handle until the vendor callback brings the real profile back; the
            // owner can refine it under "Advanced". Never blank (the column is required).
            'handle' => Str::slug($brand->name) ?: ('brand-'.$brand->id),
            'timezone' => $brand->loadMissing('organization')->organization->default_timezone,
            'language' => $brand->default_language,
            'country' => $brand->countries[0] ?? null,
            'connection_status' => ConnectionStatus::NotConnected,
        ];
    }
}
