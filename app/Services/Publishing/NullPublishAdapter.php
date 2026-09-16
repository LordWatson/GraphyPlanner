<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Support\Collection;

/**
 * No-op PublishAdapter used until a real vendor (Upload-Post, Phase 1.2+) is
 * wired up, and in tests that don't care about vendor behavior. Never
 * connects, publishes, or reports a healthy account.
 */
class NullPublishAdapter implements PublishAdapter
{
    public function connectAccount(SocialAccount $account): string
    {
        return '';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void
    {
        // No-op: no vendor to receive a callback from yet.
    }

    /**
     * @param  Collection<int, SocialAccount>  $targets
     * @return Collection<int, TargetResult>
     */
    public function publish(Post $post, Collection $targets): Collection
    {
        return $targets->map(fn (SocialAccount $account): TargetResult => new TargetResult(
            accountId: $account->id,
            ok: false,
            error: 'No publish adapter is configured yet.',
        ));
    }

    public function cancel(string $externalPostId): void
    {
        // No-op: nothing was ever published on a vendor.
    }

    public function health(SocialAccount $account): bool
    {
        return false;
    }
}
