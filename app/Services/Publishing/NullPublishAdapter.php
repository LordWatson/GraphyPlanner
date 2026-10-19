<?php

namespace App\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\CancelResult;
use App\Support\Publishing\TargetResult;
use Illuminate\Support\Collection;

/**
 * No-op PublishAdapter used until a real vendor (Upload-Post, Phase 1.2+) is
 * wired up, and in tests that don't care about vendor behavior. Never
 * connects, publishes, or reports a healthy account.
 */
class NullPublishAdapter implements PublishAdapter
{
    public function verifyCredentials(Organization $organization): bool
    {
        return false;
    }

    public function connectAccount(SocialAccount $account, ?string $state = null): string
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

    /**
     * Nothing was ever sent to a vendor, so there's nothing it could still publish.
     */
    public function cancel(PostTarget $target): CancelResult
    {
        return CancelResult::cancelled();
    }

    public function registerWebhook(Organization $organization, string $url): ?string
    {
        return null;
    }

    public function health(SocialAccount $account): bool
    {
        return false;
    }

    public function checkStatus(SocialAccount $account, string $externalPostId): ?TargetResult
    {
        return null;
    }
}
