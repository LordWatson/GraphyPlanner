<?php

namespace App\Actions\Grids;

use App\Enums\Role;
use App\Models\GridComment;
use App\Models\GridPlan;
use App\Models\User;
use App\Notifications\GridCommentNotification;
use App\Services\PostNotificationService;
use App\Support\CommentMentionParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateGridCommentAction
{
    public function __construct(private readonly PostNotificationService $notifications = new PostNotificationService) {}

    /**
     * Step 18.7 — a comment on a grid, optionally pinned to one of its tiles. Client reviewers can
     * never write an internal-only comment, whatever is submitted. `@[Name](id)` mentions resolve
     * against the grid's team and client contacts only, and are notified; the other side of the
     * conversation hears about it too (never the client for an internal note).
     *
     * `$user` is null for a comment left through a review link (the client, unauthenticated).
     */
    public function __invoke(GridPlan $plan, ?User $user, string $body, bool $internalOnly = false, ?int $tileId = null): GridComment
    {
        $byClient = $user === null || $user->role === Role::ClientReviewer;

        [$comment, $mentioned] = DB::transaction(function () use ($plan, $user, $body, $internalOnly, $tileId, $byClient) {
            /** @var GridComment $comment */
            $comment = $plan->comments()->create([
                'user_id' => $user?->id,
                'grid_tile_id' => $tileId !== null && $plan->tiles()->whereKey($tileId)->exists() ? $tileId : null,
                'body' => trim($body),
                'internal_only' => $byClient ? false : $internalOnly,
            ]);

            $ids = CommentMentionParser::extractUserIds($body);
            $mentioned = $ids === [] ? collect() : $this->notifications->mentionableRecipients($plan, exclude: $user)->whereIn('id', $ids)->values();

            if ($mentioned->isNotEmpty()) {
                $comment->mentionedUsers()->sync($mentioned->pluck('id'));
            }

            return [$comment, $mentioned];
        });

        Log::info('Grid comment created', [
            'grid_plan_id' => $plan->id,
            'grid_tile_id' => $comment->grid_tile_id,
            'user_id' => $user?->id,
            'internal_only' => $comment->internal_only,
            'mentioned_user_ids' => $mentioned->pluck('id')->all(),
        ]);

        $comment->load('user');

        foreach ($mentioned as $recipient) {
            // An internal note never reaches a client contact, even through a mention.
            if ($comment->internal_only && $recipient->role === Role::ClientReviewer) {
                continue;
            }

            $recipient->notify(new GridCommentNotification($plan, $comment, forPortal: $recipient->role === Role::ClientReviewer, mentioned: true));
        }

        $mentionedIds = $mentioned->pluck('id');

        if ($byClient) {
            $this->notifications->send($this->notifications->orgRecipients($plan)->whereNotIn('id', $mentionedIds), new GridCommentNotification($plan, $comment, forPortal: false));
        } elseif (! $comment->internal_only && $plan->status->isClientVisible()) {
            $this->notifications->send($this->notifications->clientPortalRecipients($plan)->whereNotIn('id', $mentionedIds), new GridCommentNotification($plan, $comment, forPortal: true));
        }

        return $comment;
    }
}
