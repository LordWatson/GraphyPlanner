<?php

namespace App\Actions\Posts\Approvals;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalPolicy;
use App\Enums\PostStatus;
use App\Models\ApprovalStep;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\User;
use App\Services\PostNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-6.3 — the single entry point for a client decision on a post (the portal, the review-link
 * token, and campaign batch approval all go through here). It records the decision against the
 * acting approver's step and only releases the post to `approved` once the client's approval policy
 * is satisfied:
 *
 * - **any**: one approver's approval releases it (the original behaviour);
 * - **all**: every approver must approve;
 * - **sequential**: approvers approve in order; the last one releases it.
 *
 * "Changes requested" from any approver stops the whole post immediately. A client with no named
 * approver contacts, and the anonymous review-link token, take the generic path: the decision speaks
 * for the client as a whole and releases (or stops) the post at once.
 */
class SubmitClientDecisionAction
{
    public function __invoke(Post $post, ?User $actor, ApprovalDecision $decision, ?string $comment = null, ?PostNotificationService $notifications = null): Post
    {
        $notifications ??= new PostNotificationService;

        if ($decision === ApprovalDecision::ChangesRequested) {
            return $this->requestChanges($post, $actor, $comment, $notifications);
        }

        return DB::transaction(function () use ($post, $actor, $comment, $notifications) {
            /** @var Post $locked */
            $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

            // Only a post awaiting the client takes a client decision. Anything else is handled by
            // the ordinary transition action (and its concurrency checks) directly.
            if ($locked->status !== PostStatus::WaitingClient) {
                return app(TransitionPostStatusAction::class)($locked, PostStatus::Approved, $actor, $comment, notifications: $notifications);
            }

            $policy = $locked->client->approval_policy;
            /** @var Collection<int, ApprovalStep> $steps */
            $steps = $locked->approvalSteps()->with('contact')->get();
            $revision = $locked->revisions()->whereNotNull('shared_at')->latest('id')->first();

            $actingStep = $this->resolveActingStep($steps, $actor, $policy);

            if ($actingStep === null) {
                // Generic completion: the anonymous token, or a client with no named approvers —
                // the client speaks as a whole, so every pending step is approved and the post is
                // released at once.
                $this->recordDecision($steps->whereNull('decision'), $actor, $comment, $revision);

                return $this->release($locked, $actor, $comment, $notifications);
            }

            $this->recordDecision(collect([$actingStep]), $actor, $comment, $revision);

            $satisfied = $policy === ApprovalPolicy::Any
                || $locked->approvalSteps()->whereNull('decision')->doesntExist();

            if ($satisfied) {
                return $this->release($locked, $actor, $comment, $notifications);
            }

            // A partial approval leaves the post waiting on the remaining approvers; record it on
            // the timeline so the team (and the next approver) can see progress.
            $locked->activityLogs()->create([
                'user_id' => $actor?->id,
                'from_status' => PostStatus::WaitingClient,
                'to_status' => PostStatus::WaitingClient,
                'note' => trim($actor->name.' approved — waiting on the other approvers.'.($comment ? "\n".$comment : '')),
            ]);

            Log::info('Partial client approval recorded', [
                'post_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $actor?->id,
                'policy' => $policy->value,
                'approval_step_id' => $actingStep->id,
            ]);

            $locked->refresh();
            $post->setRawAttributes($locked->getAttributes(), true);

            return $post;
        });
    }

    /**
     * Resolve the step the actor is deciding, or null for the generic (whole-client) path. Throws a
     * friendly validation error when a named approver tries to act out of turn or twice.
     *
     * @param  Collection<int, ApprovalStep>  $steps
     */
    private function resolveActingStep(Collection $steps, ?User $actor, ApprovalPolicy $policy): ?ApprovalStep
    {
        $named = $steps->whereNotNull('client_contact_id');

        // No named approvers, or an anonymous token decision → the generic whole-client path.
        if ($named->isEmpty() || $actor === null) {
            return null;
        }

        $email = mb_strtolower($actor->email);
        /** @var ApprovalStep|null $mine */
        $mine = $named->first(fn (ApprovalStep $step) => mb_strtolower((string) $step->contact?->email) === $email);

        if ($mine === null) {
            throw ValidationException::withMessages([
                'decision' => __('You’re not one of this post’s approvers.'),
            ]);
        }

        if (! $mine->isPending()) {
            throw ValidationException::withMessages([
                'decision' => __('You’ve already approved this post.'),
            ]);
        }

        if ($policy === ApprovalPolicy::Sequential) {
            $next = $named->where('decision', null)->sortBy('position')->first();

            if ($next !== null && $next->id !== $mine->id) {
                throw ValidationException::withMessages([
                    'decision' => __('It’s not your turn yet — an earlier approver still needs to sign off.'),
                ]);
            }
        }

        return $mine;
    }

    /**
     * @param  Collection<int, ApprovalStep>  $steps
     */
    private function recordDecision(Collection $steps, ?User $actor, ?string $comment, ?PostRevision $revision): void
    {
        foreach ($steps as $step) {
            $step->update([
                'decision' => ApprovalDecision::Approved,
                'decided_by_user_id' => $actor?->id,
                'decided_at' => now(),
                'comment' => $comment,
                'approved_revision_id' => $revision?->id,
            ]);
        }
    }

    private function release(Post $post, ?User $actor, ?string $comment, PostNotificationService $notifications): Post
    {
        return app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $actor, $comment, notifications: $notifications);
    }

    private function requestChanges(Post $post, ?User $actor, ?string $comment, PostNotificationService $notifications): Post
    {
        // Record the rejection on the acting step (when identifiable) before the post moves, so the
        // timeline shows who asked for changes; the steps rebuild on the next review round anyway.
        $step = $post->approvalSteps()
            ->whereNull('decision')
            ->when($actor !== null, fn ($query) => $query->whereHas(
                'contact',
                fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $actor?->email)]),
            ))
            ->orderBy('position')
            ->first();

        $step?->update([
            'decision' => ApprovalDecision::ChangesRequested,
            'decided_by_user_id' => $actor?->id,
            'decided_at' => now(),
            'comment' => $comment,
        ]);

        return app(TransitionPostStatusAction::class)($post, PostStatus::ChangesRequested, $actor, $comment, notifications: $notifications);
    }
}
