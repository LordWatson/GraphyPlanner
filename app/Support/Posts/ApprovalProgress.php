<?php

namespace App\Support\Posts;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalPolicy;
use App\Enums\PostStatus;
use App\Models\ApprovalStep;
use App\Models\Post;
use App\Models\User;

/**
 * Step M-6.3 — presents a post's multi-approver progress for the portal and review views, and
 * answers "may this reviewer decide right now?" so the Approve / Request-changes buttons only show
 * to the approver whose turn it is.
 */
final class ApprovalProgress
{
    /**
     * @return array{policy: string, policy_label: string, multi: bool, waiting_on: array<int, string>, steps: array<int, array{approver: string, status: string, is_turn: bool, decided_at: string|null}>}|null
     */
    public static function present(Post $post): ?array
    {
        $post->loadMissing('approvalSteps.contact', 'client');
        $policy = $post->client->approval_policy;
        $named = $post->approvalSteps->whereNotNull('client_contact_id')->sortBy('position')->values();

        // Only worth surfacing for a real multi-approver set-up.
        if ($named->count() < 2 && $policy === ApprovalPolicy::Any) {
            return null;
        }

        $currentId = $policy === ApprovalPolicy::Sequential
            ? $named->firstWhere('decision', null)?->id
            : null;

        return [
            'policy' => $policy->value,
            'policy_label' => $policy->label(),
            'multi' => $named->count() > 1,
            // `$named` is the contact-backed steps only, so `contact` is always present here.
            'waiting_on' => $named->where('decision', null)
                ->map(fn (ApprovalStep $step) => $step->contact->name ?? $step->contact->email ?? 'An approver')
                ->values()->all(),
            'steps' => $named->map(fn (ApprovalStep $step) => [
                'approver' => $step->contact->name ?? $step->contact->email ?? 'An approver',
                'status' => $step->decision instanceof ApprovalDecision ? $step->decision->value : 'pending',
                'is_turn' => $policy === ApprovalPolicy::Sequential ? $step->id === $currentId : $step->isPending(),
                'decided_at' => $step->decided_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Whether `$user` may approve/request-changes on `$post` right now, honouring the client's
     * policy (and, for sequential, whose turn it is). Mirrors `SubmitClientDecisionAction`.
     */
    public static function canDecide(Post $post, ?User $user): bool
    {
        if ($post->status !== PostStatus::WaitingClient) {
            return false;
        }

        $post->loadMissing('approvalSteps.contact', 'client');
        $named = $post->approvalSteps->whereNotNull('client_contact_id');

        // Generic step (no named approvers), or an anonymous reviewer: anyone with access decides.
        if ($named->isEmpty() || $user === null) {
            return true;
        }

        $email = mb_strtolower($user->email);
        /** @var ApprovalStep|null $mine */
        $mine = $named->first(fn (ApprovalStep $step) => mb_strtolower((string) $step->contact->email) === $email);

        if ($mine === null || ! $mine->isPending()) {
            return false;
        }

        if ($post->client->approval_policy === ApprovalPolicy::Sequential) {
            $next = $named->where('decision', null)->sortBy('position')->first();

            return $next !== null && $next->id === $mine->id;
        }

        return true;
    }
}
