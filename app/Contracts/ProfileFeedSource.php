<?php

namespace App\Contracts;

use App\Models\SocialAccount;
use App\Support\Grids\ProfileFeed;

/**
 * Step 18.3 — what's already on an Instagram profile, for the grid preview's "Already on the
 * profile" section and its header (username, picture, post count). Bound to
 * `CompositeProfileFeedSource`: Meta's Graph API when the account has done the Facebook Login
 * connect, else the posts the app itself published. Implementations never throw to the page.
 */
interface ProfileFeedSource
{
    public function recent(SocialAccount $account, int $limit = 30): ProfileFeed;
}
