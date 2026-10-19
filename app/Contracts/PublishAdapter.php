<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Publishing\CancelResult;
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
     *
     * Step I-1.6: `$state` is a signed, single-use value (`ConnectStateStore`) the adapter must
     * round-trip on the callback URL, so the callback can reject forged or replayed requests.
     */
    public function connectAccount(SocialAccount $account, ?string $state = null): string;

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
     * AI disclosure (Step 16.13): when `$post->hasAiGeneratedMedia()` is true, adapters must set
     * the platform's AI-generated-content label wherever the vendor supports one.
     *
     * @param  Collection<int, SocialAccount>  $targets
     * @return Collection<int, TargetResult>
     */
    public function publish(Post $post, Collection $targets): Collection;

    /**
     * Step I-3.6 / M-2.5: cancel a publish the vendor is holding for a future time (the target's
     * `external_post_id` is the vendor's job id). Takes the target so the request authenticates
     * as its organization. Must never report success unless the vendor confirmed the cancel —
     * callers rely on that to avoid double posts.
     */
    public function cancel(PostTarget $target): CancelResult;

    /**
     * Step I-3.6 / M-2.5: register `$url` as the organization's publish-outcome webhook with the
     * vendor and return the signing secret it issues (null when registration failed).
     */
    public function registerWebhook(Organization $organization, string $url): ?string;

    /**
     * Check the current connection health of a SocialAccount against the
     * vendor (e.g. to detect an expired token).
     */
    public function health(SocialAccount $account): bool;

    /**
     * Step I-1.6: whether the vendor accepts this organization's stored credentials ("Test
     * connection" in organization settings). Never publishes anything.
     */
    public function verifyCredentials(Organization $organization): bool;

    /**
     * Poll the vendor for the final outcome of a previously accepted publish
     * (Step 1.5's webhook fallback). Returns null when the vendor hasn't
     * resolved the post yet (still processing) or doesn't support polling,
     * so the caller should try again later rather than treating null as a
     * failure.
     */
    public function checkStatus(SocialAccount $account, string $externalPostId): ?TargetResult;
}
