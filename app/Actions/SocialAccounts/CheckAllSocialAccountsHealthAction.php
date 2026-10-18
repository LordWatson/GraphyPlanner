<?php

namespace App\Actions\SocialAccounts;

use App\Contracts\PublishAdapter;
use App\Enums\ConnectionStatus;
use App\Models\SocialAccount;

/**
 * Step 1.6 scheduled sweep: runs {@see CheckSocialAccountHealthAction} against every `connected`
 * SocialAccount so a vendor-side token revocation is caught even if nothing else touches that
 * account (no publish attempt, no manual reconnect) — it's what actually keeps
 * `connection_status`, `ClientHealthService`, and the Home "needs attention" list current.
 *
 * Step I-1.8: walks accounts with `lazyById()` — the old `each()` paged by offset while the
 * check flipped `connection_status` under it, which could skip accounts.
 */
class CheckAllSocialAccountsHealthAction
{
    public function __invoke(PublishAdapter $adapter, CheckSocialAccountHealthAction $checkHealth): int
    {
        $flagged = 0;

        $accounts = SocialAccount::query()
            ->where('connection_status', ConnectionStatus::Connected)
            ->lazyById(200);

        foreach ($accounts as $account) {
            if (! $checkHealth($adapter, $account)) {
                $flagged++;
            }
        }

        return $flagged;
    }
}
