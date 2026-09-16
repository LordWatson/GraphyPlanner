<?php

namespace App\Actions\SocialAccounts;

use App\Contracts\PublishAdapter;
use App\Models\SocialAccount;

/**
 * Starts the account-connect flow (spec §7, Step 1.3) by asking the bound `PublishAdapter` for a
 * vendor connect/OAuth URL. The controller is only responsible for authorizing the request and
 * redirecting the browser to the returned URL — this class owns the adapter call so it stays
 * testable independent of HTTP.
 */
class ConnectSocialAccountAction
{
    public function __construct(private readonly PublishAdapter $adapter) {}

    public function __invoke(SocialAccount $socialAccount): string
    {
        return $this->adapter->connectAccount($socialAccount);
    }
}
