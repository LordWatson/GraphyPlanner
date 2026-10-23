<?php

namespace App\Services\Posts;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Contracts\PostWorkflow;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;

/**
 * Step B-3.1 — the business/creator workflow. A business workspace has no client, so the two
 * client-only stages (`waiting_client`, `changes_requested`) are removed entirely. What's left
 * depends on the org's `require_post_review` switch (Step B-3.3):
 *
 * - **Review off** (the default, solo owner): `draft → approved` self-approval — one tap from a
 *   draft to "ready", recorded on the activity log as "Marked ready".
 * - **Review on** (a small team): `draft → internal_review → approved`, with `internal_review →
 *   draft` meaning "changes needed". An existing post already sitting in `internal_review` can
 *   always still be approved, so flipping the switch off never strands in-flight work.
 *
 * Every other edge (idea→draft, approved→scheduled→publishing→published, failed/retry, archive,
 * recall) is inherited unchanged from `PostStatusTransitionMap` — this class only narrows the map,
 * it never invents an edge.
 */
class BusinessPostWorkflow implements PostWorkflow
{
    /**
     * The client-only statuses a business workspace never uses, as either a source or a destination.
     *
     * @var list<PostStatus>
     */
    private const CLIENT_ONLY = [PostStatus::WaitingClient, PostStatus::ChangesRequested];

    /**
     * @return list<PostStatus>
     */
    public function allowedTransitions(Post $post, ?User $user = null): array
    {
        $post->loadMissing('organization');

        $map = $this->mapFor($this->requiresReview($post->organization));

        return array_map(
            static fn (string $value): PostStatus => PostStatus::from($value),
            $map[$post->status->value] ?? [],
        );
    }

    public function defaultApprovalMode(): ApprovalMode
    {
        return ApprovalMode::InternalOnly;
    }

    public function requiresReview(Organization $organization): bool
    {
        return $organization->require_post_review;
    }

    /**
     * The business transition map for a given review setting, derived from the shared
     * `PostStatusTransitionMap` so inherited edges can never drift out of sync with the engine.
     *
     * @return array<string, list<string>>
     */
    private function mapFor(bool $reviewOn): array
    {
        $clientOnly = array_map(static fn (PostStatus $status): string => $status->value, self::CLIENT_ONLY);

        $map = [];

        foreach (PostStatusTransitionMap::MAP as $from => $destinations) {
            if (in_array($from, $clientOnly, true)) {
                continue;
            }

            $map[$from] = array_values(array_filter(
                $destinations,
                static fn (string $to): bool => ! in_array($to, $clientOnly, true),
            ));
        }

        // Draft is where the two shapes diverge: self-approve directly when review is off, or hand
        // off to the review stage when it's on.
        $map[PostStatus::Draft->value] = $reviewOn
            ? [PostStatus::InternalReview->value, PostStatus::Archived->value]
            : [PostStatus::Approved->value, PostStatus::Archived->value];

        // Internal review always offers both "approve" and "back to draft" (changes needed). It's
        // only reachable when review is on, but keeping the row populated means a post already in
        // review stays actionable if the owner later turns the switch off (Step B-3.3).
        $map[PostStatus::InternalReview->value] = [
            PostStatus::Draft->value,
            PostStatus::Approved->value,
            PostStatus::Archived->value,
        ];

        return $map;
    }
}
