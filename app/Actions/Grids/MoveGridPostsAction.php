<?php

namespace App\Actions\Grids;

use App\Actions\Posts\BulkTransitionPostsAction;
use App\Enums\PostStatus;
use App\Mail\GridPostsReadyMail;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MoveGridPostsAction
{
    public const SUBMIT = 'submit';

    public const SEND_TO_CLIENT = 'send_to_client';

    public const SCHEDULE = 'schedule';

    public const ACTIONS = [self::SUBMIT, self::SEND_TO_CLIENT, self::SCHEDULE];

    public function __construct(private readonly BulkTransitionPostsAction $bulk) {}

    /**
     * Steps 18.9 / 18.10 — move a grid's posts together, in publish order: submit the drafts for
     * internal review, send the captions to the client as one batch review (one email for the
     * grid, not one per post), or schedule every approved post. Each post goes through
     * `TransitionPostStatusAction` on its own, with its own checklist; anything that can't move is
     * listed with the reason — never skipped silently, and nothing is ever scheduled on its own.
     *
     * @return array{done: list<int>, skipped: list<array{id: int, position: int|null, status: string, reason: string}>}
     */
    public function __invoke(GridPlan $plan, User $user, string $action): array
    {
        $tiles = GridTile::query()
            ->where('grid_plan_id', $plan->id)
            ->whereNotNull('post_id')
            ->with('post')
            ->orderByDesc('position')
            ->get()
            ->filter(fn (GridTile $tile) => $tile->post !== null);

        $to = match ($action) {
            self::SUBMIT => PostStatus::InternalReview,
            self::SEND_TO_CLIENT => PostStatus::WaitingClient,
            default => PostStatus::Scheduled,
        };

        // Scheduling only ever touches posts whose words are approved.
        $candidates = $action === self::SCHEDULE
            ? $tiles->filter(fn (GridTile $tile) => $tile->post->status === PostStatus::Approved)
            : $tiles;

        $result = ($this->bulk)($candidates->map(fn (GridTile $tile) => $tile->post)->values(), $to, $user, $action === self::SEND_TO_CLIENT);

        $positions = $tiles->mapWithKeys(fn (GridTile $tile) => [$tile->post_id => $tile->position]);
        $statuses = $tiles->mapWithKeys(fn (GridTile $tile) => [$tile->post_id => $tile->post->status]);

        $skipped = array_map(fn (array $skip) => [
            'id' => $skip['id'],
            'position' => $positions->get($skip['id']),
            'status' => $statuses->get($skip['id'])?->label() ?? '',
            'reason' => $skip['reason'],
        ], $result['skipped']);

        if ($action === self::SCHEDULE) {
            foreach ($tiles->reject(fn (GridTile $tile) => $candidates->contains('id', $tile->id)) as $tile) {
                if (in_array($tile->post->status, [PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Published, PostStatus::PartiallyPublished], true)) {
                    continue;
                }

                $skipped[] = [
                    'id' => $tile->post->id,
                    'position' => $tile->position,
                    'status' => $tile->post->status->label(),
                    'reason' => 'Its caption isn’t approved yet.',
                ];
            }
        }

        if ($action === self::SEND_TO_CLIENT && $result['done'] !== []) {
            $this->emailClientOnce($plan, count($result['done']));
        }

        Log::info('Grid posts moved', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'action' => $action,
            'done' => count($result['done']),
            'skipped' => count($skipped),
        ]);

        return ['done' => $result['done'], 'skipped' => $skipped];
    }

    private function emailClientOnce(GridPlan $plan, int $count): void
    {
        $plan->loadMissing('client');
        // Step M-6.1: reach every approver contact that opted into review emails (legacy fallback).
        $recipients = $plan->client->reviewEmailRecipients();

        if ($recipients === []) {
            Log::warning('Skipped grid captions email — no review-email contacts on file', ['grid_plan_id' => $plan->id]);

            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient['email'])->queue(new GridPostsReadyMail($plan, $count));
        }
    }
}
