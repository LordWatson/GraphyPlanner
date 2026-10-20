<?php

namespace App\Actions\Grids;

use App\Enums\GridPlanStatus;
use App\Enums\Role;
use App\Exceptions\Grids\InvalidGridTransitionException;
use App\Exceptions\Grids\StaleGridPlanException;
use App\Models\GridPlan;
use App\Models\User;
use App\Notifications\GridPlanStatusChanged;
use App\Services\Grids\GridPlanService;
use App\Services\PostNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransitionGridPlanStatusAction
{
    /** Set only while `overrideApproval()` runs. */
    private bool $overriding = false;

    public function __construct(
        private readonly EvaluateGridChecklistAction $checklist = new EvaluateGridChecklistAction,
        private readonly SendClientGridReviewRequestedEmailAction $sendReviewEmail = new SendClientGridReviewRequestedEmailAction,
        private readonly PostNotificationService $notifications = new PostNotificationService,
        private readonly GridPlanService $grids = new GridPlanService,
    ) {}

    /**
     * Step 18.7 — an Owner approves a grid whose client must sign off, without the client. The
     * reason is required and lands on the activity log ("Approval override: …").
     */
    public function overrideApproval(GridPlan $plan, User $owner, string $reason): GridPlan
    {
        $this->overriding = true;

        try {
            $result = $this($plan, GridPlanStatus::Approved, $owner, 'Approval override: '.trim($reason));
        } finally {
            $this->overriding = false;
        }

        Log::warning('Client grid approval overridden', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $owner->id,
        ]);

        return $result;
    }

    /**
     * Step 18.7 — move a grid plan through `GridPlanStatusTransitionMap`. Callers authorize the
     * move first (`GridPlanPolicy::transition`). The plan row is locked and the move re-checked
     * against the locked status, so two simultaneous decisions can't both pass.
     *
     * - `waiting_client` and `approved` need a passing hard checklist.
     * - `$expectedVersion` (the client's decision) refuses a grid that changed while they looked.
     * - Approval stamps `approved_at`/`approved_by` and the `approved_layout_hash` the drift check
     *   compares against; leaving `approved` clears them.
     * - `waiting_client` emails the client a grid review link (its token id goes on the log).
     * - Notifications (internal review, client review, the client's decision) go out after commit.
     *
     * `$user` is null for a decision made through a review link.
     */
    public function __invoke(GridPlan $plan, GridPlanStatus $to, ?User $user, ?string $note = null, ?int $expectedVersion = null): GridPlan
    {
        if (! $this->allowed($plan, $to)) {
            throw new InvalidGridTransitionException($plan->id, $plan->status, $to);
        }

        $updated = DB::transaction(function () use ($plan, $to, $user, $note, $expectedVersion) {
            /** @var GridPlan $locked */
            $locked = GridPlan::query()->withoutGlobalScopes()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $this->allowed($locked, $to, $from)) {
                Log::warning('Grid transition rejected: status changed concurrently', [
                    'grid_plan_id' => $locked->id,
                    'user_id' => $user?->id,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                ]);

                throw new InvalidGridTransitionException($locked->id, $from, $to);
            }

            if ($expectedVersion !== null && $expectedVersion !== $locked->version) {
                throw new StaleGridPlanException($locked->id, $expectedVersion, $locked->version, __('This grid changed while you were looking. Here’s the latest.'));
            }

            $checklist = ($this->checklist)($locked);

            if (in_array($to, [GridPlanStatus::WaitingClient, GridPlanStatus::Approved], true) && ! $checklist['passed']) {
                $failing = collect($checklist['items'])->filter(fn (array $item) => ! $item['passed'] && ! $item['soft'])->pluck('label')->implode(', ');

                throw ValidationException::withMessages(['to' => __('Finish the checklist first: :items.', ['items' => strtolower($failing)])]);
            }

            $attributes = ['status' => $to, 'checklist_snapshot' => $checklist];

            if ($to === GridPlanStatus::Approved) {
                $attributes += [
                    'approved_at' => now(),
                    'approved_by' => $user?->id,
                    'approved_layout_hash' => $this->grids->layoutHash($locked),
                    'order_risk_notified_at' => null,
                ];
            } elseif ($from === GridPlanStatus::Approved) {
                $attributes += ['approved_at' => null, 'approved_by' => null, 'approved_layout_hash' => null];
            }

            $locked->update($attributes);

            $reviewToken = $to === GridPlanStatus::WaitingClient ? ($this->sendReviewEmail)($locked) : null;

            $locked->activityLogs()->create([
                'user_id' => $user?->id,
                'from_status' => $from,
                'to_status' => $to,
                'note' => filled($note) ? trim((string) $note) : null,
                'review_token_id' => $reviewToken?->id,
            ]);

            Log::info('Grid plan status transitioned', [
                'grid_plan_id' => $locked->id,
                'org_id' => $locked->org_id,
                'user_id' => $user?->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);

            DB::afterCommit(fn () => $this->notify($locked, $to, $user));

            return $locked;
        });

        $plan->setRawAttributes($updated->getAttributes(), true);

        return $plan;
    }

    private function allowed(GridPlan $plan, GridPlanStatus $to, ?GridPlanStatus $from = null): bool
    {
        $from ??= $plan->status;

        return $this->overriding
            ? GridPlanStatusTransitionMap::isAllowed($from, $to) && GridPlanStatusTransitionMap::needsClientApproval($plan, $from, $to)
            : GridPlanStatusTransitionMap::isAllowedFor($plan, $to, $from);
    }

    private function notify(GridPlan $plan, GridPlanStatus $to, ?User $user): void
    {
        $byClient = $user === null || $user->role === Role::ClientReviewer;

        $recipients = match (true) {
            $to === GridPlanStatus::InternalReview => $this->notifications->orgRecipients($plan, $user),
            $to === GridPlanStatus::WaitingClient => $this->notifications->clientPortalRecipients($plan),
            in_array($to, [GridPlanStatus::Approved, GridPlanStatus::ChangesRequested], true) && $byClient => $this->notifications->orgRecipients($plan),
            default => collect(),
        };

        $this->notifications->send($recipients, new GridPlanStatusChanged($plan, $to, forPortal: $to === GridPlanStatus::WaitingClient));

        if ($recipients->isNotEmpty()) {
            Log::info('Grid plan notification sent', [
                'grid_plan_id' => $plan->id,
                'to_status' => $to->value,
                'recipient_count' => $recipients->count(),
            ]);
        }
    }
}
