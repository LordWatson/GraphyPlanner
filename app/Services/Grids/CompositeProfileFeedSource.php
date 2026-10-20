<?php

namespace App\Services\Grids;

use App\Contracts\ProfileFeedSource;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Support\Grids\ProfileFeed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Step 18.3 — the bound `ProfileFeedSource`: Meta's Graph API when the account has done the
 * Facebook Login connect, else the posts the app published, else an empty feed. Never throws to
 * the page; a Graph failure logs a warning with the account id and falls back.
 */
class CompositeProfileFeedSource implements ProfileFeedSource
{
    public function __construct(
        private readonly MetaGraphProfileFeedSource $meta,
        private readonly PublishedPostsProfileFeedSource $published,
    ) {}

    public function recent(SocialAccount $account, int $limit = 30): ProfileFeed
    {
        if ($account->platform !== Platform::Instagram) {
            return ProfileFeed::none(ltrim($account->handle, '@'));
        }

        $limit = max(1, min((int) config('grids.feed_limit', 30), $limit));

        if (MetaGraphProfileFeedSource::canRead($account)) {
            try {
                return $this->meta->recent($account, $limit);
            } catch (Throwable $e) {
                Log::warning('Instagram profile feed fell back to app posts', [
                    'social_account_id' => $account->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        try {
            return $this->published->recent($account, $limit);
        } catch (Throwable $e) {
            Log::error('Instagram profile feed unavailable', ['social_account_id' => $account->id, 'reason' => $e->getMessage()]);

            return ProfileFeed::none(ltrim($account->handle, '@'));
        }
    }

    /**
     * "Refresh": drop the cached Graph feed for this account, at most
     * `grids.feed_refresh_per_hour` times an hour. Returns false when rate-limited.
     */
    public function refresh(SocialAccount $account): bool
    {
        $key = "grid-feed-refresh:{$account->id}";

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('grids.feed_refresh_per_hour', 6)))) {
            Log::info('Instagram profile feed refresh rate-limited', ['social_account_id' => $account->id]);

            return false;
        }

        RateLimiter::hit($key, 3600);
        $this->meta->forget($account);

        Log::info('Instagram profile feed cache cleared', ['social_account_id' => $account->id]);

        return true;
    }
}
