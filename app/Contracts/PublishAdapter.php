<?php

namespace App\Contracts;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Publishing\TargetResult;
use Illuminate\Support\Collection;

/**
 * Contract for a publishing vendor integration (spec §7), starting with
 * Upload-Post (Phase 1) and kept swappable so a second adapter (Phase 4) can
 * be implemented against this same interface without touching any caller.
 */
interface PublishAdapter
{
    /**
     * Start (or return) the connect/OAuth URL for linking a SocialAccount to
     * this vendor. Does not itself flip connectionStatus — that happens once
     * handleCallback() completes the flow.
     */
    public function connectAccount(SocialAccount $account): string;

    /**
     * Handle the vendor's OAuth/connect callback query, persisting whatever
     * external identifiers the vendor returns (e.g. externalProfileId) and
     * updating the account's connectionStatus.
     *
     * @param  array<string, mixed>  $query
     */
    public function handleCallback(array $query): void;

    /**
     * Publish (or schedule) a post to the given SocialAccount targets.
     *
     * @param  Collection<int, SocialAccount>  $targets
     * @return Collection<int, TargetResult>
     */
    public function publish(Post $post, Collection $targets): Collection;

    /**
     * Cancel a previously scheduled/published post on the vendor, if
     * supported. Optional — adapters that can't cancel should no-op.
     */
    public function cancel(string $externalPostId): void;

    /**
     * Check the current connection health of a SocialAccount against the
     * vendor (e.g. to detect an expired token).
     */
    public function health(SocialAccount $account): bool;

    /**
     * Poll the vendor for the final outcome of a previously accepted publish
     * (Step 1.5's webhook fallback). Returns null when the vendor hasn't
     * resolved the post yet (still processing) or doesn't support polling,
     * so the caller should try again later rather than treating null as a
     * failure.
     */
    public function checkStatus(SocialAccount $account, string $externalPostId): ?TargetResult;
}
