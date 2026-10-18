<?php

namespace App\Actions\SocialAccounts;

use App\Contracts\PublishAdapter;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ConnectStateStore;

/**
 * Starts the account-connect flow (spec §7, Step 1.3) by asking the bound `PublishAdapter` for a
 * vendor connect/OAuth URL. The controller is only responsible for authorizing the request and
 * redirecting the browser to the returned URL — this class owns the adapter call so it stays
 * testable independent of HTTP.
 *
 * Step I-1.6: issues the signed, single-use `state` the callback must present.
 */
class ConnectSocialAccountAction
{
    public const STATE_FLOW = 'upload-post';

    public function __construct(
        private readonly PublishAdapter $adapter,
        private readonly ConnectStateStore $states = new ConnectStateStore,
    ) {}

    public function __invoke(SocialAccount $socialAccount, ?User $user = null): string
    {
        $state = $this->states->issue($socialAccount, $user, self::STATE_FLOW);

        return $this->adapter->connectAccount($socialAccount, $state);
    }
}
