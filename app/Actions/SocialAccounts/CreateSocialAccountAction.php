<?php

namespace App\Actions\SocialAccounts;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Exceptions\Plans\PlanLimitReachedException;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Services\Plans\PlanUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateSocialAccountAction
{
    /**
     * Create a new social account for the given client.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PlanLimitReachedException when the org's plan caps connected accounts.
     */
    public function __invoke(Client $client, array $data): SocialAccount
    {
        // Step CG-2.2 / B-9.1: enforce the plan's connected-account limit (org-wide). Agency plans
        // are unlimited, so this never fires for them.
        $organization = $client->organization;

        if ($organization !== null) {
            $used = (new PlanUsage)->socialAccounts($organization);

            app(Entitlements::class)->ensure($organization, Feature::SocialAccounts, $used);
        }

        return DB::transaction(function () use ($client, $data) {
            $socialAccount = SocialAccount::create([
                ...$data,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
            ]);

            Log::info('Social account created', [
                'client_id' => $client->id,
                'social_account_id' => $socialAccount->id,
                'platform' => $socialAccount->platform->value,
            ]);

            return $socialAccount;
        });
    }
}
