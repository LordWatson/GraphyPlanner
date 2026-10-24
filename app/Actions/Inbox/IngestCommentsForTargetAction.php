<?php

namespace App\Actions\Inbox;

use App\Contracts\CommentSource;
use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\MessageDirection;
use App\Exceptions\Inbox\CommentSourceException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Inbox\SentimentClassifier;
use App\Support\Inbox\InboundComment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-9.1 — pull the comments for one published post target and fold them into the inbox:
 *
 * 1. read them through the bound `CommentSource` (Upload-Post, or the Null source);
 * 2. group them into threads by their root comment (a reply walks its parent chain to the root);
 * 3. upsert one {@see InboxConversation} per thread and one {@see InboxMessage} per comment,
 *    idempotently on the network's own ids, so re-running never duplicates — and a reply we already
 *    sent (stored outbound) is left untouched when the vendor echoes it back as a comment;
 * 4. classify each new inbound comment's sentiment (deterministic, no AI) and roll the thread's
 *    sentiment / "reply needed" / timestamps up from its messages.
 *
 * Wrapped in a transaction per target. Never logs comment bodies. `comments_synced_at` is stamped by
 * the caller (`SyncOrganizationCommentsAction`) so a failure here doesn't mark the target fresh.
 *
 * @phpstan-type IngestSummary array{conversations: int, messages: int}
 */
class IngestCommentsForTargetAction
{
    public function __construct(
        private readonly CommentSource $source,
        private readonly SentimentClassifier $sentiment = new SentimentClassifier,
    ) {}

    /**
     * @return IngestSummary
     *
     * @throws CommentSourceException
     */
    public function __invoke(PostTarget $target, ?CarbonInterface $since = null): array
    {
        $account = $target->socialAccount;

        if (! $account instanceof SocialAccount) {
            return ['conversations' => 0, 'messages' => 0];
        }

        $reading = ($this->source)->fetchComments($target, $since);

        if ($reading->isEmpty()) {
            return ['conversations' => 0, 'messages' => 0];
        }

        $byRoot = $this->groupByRoot($reading->comments);
        $summary = ['conversations' => 0, 'messages' => 0];

        DB::transaction(function () use ($account, $target, $byRoot, &$summary) {
            foreach ($byRoot as $rootId => $comments) {
                $root = $comments[0];

                $conversation = InboxConversation::query()->firstOrNew([
                    'social_account_id' => $account->id,
                    'external_conversation_id' => (string) $rootId,
                ]);

                if (! $conversation->exists) {
                    $conversation->fill([
                        'org_id' => $account->org_id,
                        'client_id' => $account->client_id,
                        'post_target_id' => $target->id,
                        'platform' => $account->platform,
                        'status' => InboxStatus::Open,
                        'author_name' => $root->authorName,
                        'author_handle' => $root->authorHandle,
                        'author_avatar_url' => $root->authorAvatarUrl,
                        'permalink' => $root->permalink,
                    ]);
                    $conversation->save();
                    $summary['conversations']++;
                }

                foreach ($comments as $comment) {
                    if ($this->upsertMessage($conversation, $comment)) {
                        $summary['messages']++;
                    }
                }

                $this->rollUpConversation($conversation);
            }
        });

        Log::info('Inbox comments ingested', [
            'org_id' => $account->org_id,
            'client_id' => $account->client_id,
            'post_target_id' => $target->id,
            'platform' => $account->platform->value,
            'conversations' => $summary['conversations'],
            'messages' => $summary['messages'],
        ]);

        return $summary;
    }

    /**
     * Create a new inbound message for a comment we haven't seen. Existing messages (including a
     * reply we sent, stored outbound and keyed by the vendor's id) are left exactly as they are, so
     * the sync never flips our own reply to inbound or overwrites its delivery state.
     */
    private function upsertMessage(InboxConversation $conversation, InboundComment $comment): bool
    {
        $exists = $conversation->messages()
            ->where('external_message_id', $comment->externalId)
            ->exists();

        if ($exists) {
            return false;
        }

        $conversation->messages()->create([
            'org_id' => $conversation->org_id,
            'external_message_id' => $comment->externalId,
            'parent_external_id' => $comment->parentExternalId,
            'direction' => MessageDirection::Inbound,
            'author_name' => $comment->authorName,
            'author_handle' => $comment->authorHandle,
            'body' => $comment->body,
            'sentiment' => $this->sentiment->classify($comment->body),
            'posted_at' => $comment->postedAt ?? now(),
        ]);

        return true;
    }

    /**
     * Recompute the thread's sentiment, "reply needed" flag and timestamps from its messages. A
     * thread needs a reply when its most recent message is an inbound question or complaint with no
     * studio reply after it; the thread's sentiment is its most urgent inbound tone.
     */
    private function rollUpConversation(InboxConversation $conversation): void
    {
        /** @var Collection<int, InboxMessage> $messages */
        $messages = $conversation->messages()->get();

        if ($messages->isEmpty()) {
            return;
        }

        $inbound = $messages->where('direction', MessageDirection::Inbound);

        $topSentiment = $inbound
            ->map(fn (InboxMessage $m) => $m->sentiment ?? CommentSentiment::Neutral)
            ->sortByDesc(fn (CommentSentiment $s) => $s->rank())
            ->first() ?? CommentSentiment::Neutral;

        // `$messages` is non-empty (guarded above), so `last()` always returns a message here.
        $last = $messages->last();
        $needsReply = $last->direction === MessageDirection::Inbound
            && ($last->sentiment ?? CommentSentiment::Neutral)->needsReply();

        $conversation->sentiment = $topSentiment;
        $conversation->is_reply_needed = $needsReply;
        $conversation->last_message_at = $messages->max('posted_at');
        $conversation->last_inbound_at = $inbound->max('posted_at') ?? $conversation->last_inbound_at;

        // A new inbound comment on a resolved thread reopens it so it isn't missed.
        if ($needsReply && $conversation->status === InboxStatus::Resolved) {
            $conversation->status = InboxStatus::Open;
            $conversation->resolved_at = null;
            $conversation->resolved_by = null;
        }

        $conversation->save();
    }

    /**
     * Group a flat list of comments by the root of their thread. A reply follows its parent chain
     * (within the batch) up to a comment with no parent; a comment whose parent isn't in the batch
     * roots at that parent id, so its replies still gather under one key.
     *
     * @param  list<InboundComment>  $comments
     * @return array<string, list<InboundComment>>
     */
    private function groupByRoot(array $comments): array
    {
        $byId = [];

        foreach ($comments as $comment) {
            $byId[$comment->externalId] = $comment;
        }

        $groups = [];

        foreach ($comments as $comment) {
            $groups[$this->rootId($comment, $byId)][] = $comment;
        }

        // Each group's own comments sorted oldest-first so the root is first and messages read in order.
        foreach ($groups as $rootId => $group) {
            usort($group, fn (InboundComment $a, InboundComment $b) => $this->postedOrder($a) <=> $this->postedOrder($b));
            $groups[$rootId] = $group;
        }

        return $groups;
    }

    /**
     * @param  array<string, InboundComment>  $byId
     */
    private function rootId(InboundComment $comment, array $byId): string
    {
        $current = $comment;
        $seen = [];

        while ($current->parentExternalId !== null && $current->parentExternalId !== '' && ! isset($seen[$current->externalId])) {
            $seen[$current->externalId] = true;
            $parent = $byId[$current->parentExternalId] ?? null;

            if ($parent === null) {
                // Parent not in this batch: root the thread at the parent id itself.
                return $current->parentExternalId;
            }

            $current = $parent;
        }

        return $current->externalId;
    }

    private function postedOrder(InboundComment $comment): int
    {
        return $comment->postedAt === null ? 0 : $comment->postedAt->getTimestamp();
    }
}
