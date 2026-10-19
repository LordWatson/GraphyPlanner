<?php

namespace App\Actions\SocialAccounts;

use App\Enums\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountTokenExpiring;
use App\Services\Publishing\MetaTokenService;
use Illuminate\Support\Facades\Log;

/**
 * Step I-3.9 / M-2.7 — keeps an account's stored Meta token (Instagram music search, Step 1.9)
 * from lapsing unnoticed. Inside the last 7 days it first tries to refresh the token; when Meta
 * won't, it warns once at 7 days and once at 1 day (a bell notification to the org's Owners and
 * Strategists), and records the stage on the account — which also puts the account on Home and
 * turns the client's health Amber (`ClientHealthService`).
 */
class CheckTokenExpiryAction
{
    public function __construct(private readonly MetaTokenService $meta) {}

    /**
     * @return string|null the warning stage now on the account (`7d`, `1d`, `expired`)
     */
    public function __invoke(SocialAccount $account): ?string
    {
        $stage = $account->tokenExpiryStage();

        if ($stage !== null && $stage !== SocialAccount::TOKEN_EXPIRED && $this->meta->refresh($account)) {
            $stage = $account->refresh()->tokenExpiryStage();
        }

        if ($stage === $account->token_expiry_warning) {
            return $stage;
        }

        $previous = $account->token_expiry_warning;
        $account->update(['token_expiry_warning' => $stage]);

        if ($stage !== null && $stage !== SocialAccount::TOKEN_EXPIRED) {
            $recipients = User::query()
                ->where('org_id', $account->org_id)
                ->whereIn('role', [Role::Owner, Role::Strategist])
                ->get();

            foreach ($recipients as $recipient) {
                $recipient->notify(new SocialAccountTokenExpiring($account, $stage));
            }
        }

        Log::info('Social account token expiry stage changed', [
            'social_account_id' => $account->id,
            'client_id' => $account->client_id,
            'from' => $previous,
            'to' => $stage,
        ]);

        return $stage;
    }
}
