<?php

namespace App\Actions\Posts;

use App\Enums\CommentAnchorType;
use App\Enums\Role;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Notifications\PostCommentAdded;
use App\Notifications\PostCommentMentioned;
use App\Services\PostNotificationService;
use App\Support\CommentMentionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreatePostCommentAction
{
    public function __construct(private readonly PostNotificationService $notifications = new PostNotificationService) {}

    /**
     * Add a comment to a post. Client reviewers can never write an internal-only comment,
     * regardless of what's submitted — internal notes must stay invisible to clients. `@`-mention
     * tokens (`@[Name](id)`) in the body are resolved against the post's mentionable users
     * (org + client-portal) and stored/notified, independently of the internal-only flag.
     *
     * Step M-6.4 — a comment can be pinned to the media/caption (`$anchorType`/`$anchor`), carry a
     * suggested caption edit, or be a reply inside a thread (`$parentId`). A reply is always a plain
     * comment (any anchor/suggestion is ignored) and can never be internal-only when the root is
     * client-visible, so a client reviewer always sees the whole thread they're part of.
     *
     * @param  array<string, mixed>|null  $anchor
     */
    public function __invoke(
        Post $post,
        User $user,
        string $body,
        bool $internalOnly = false,
        ?CommentAnchorType $anchorType = null,
        ?array $anchor = null,
        ?int $parentId = null,
        ?string $suggestedEdit = null,
    ): PostComment {
        $isReply = $parentId !== null;

        $comment = DB::transaction(function () use ($post, $user, $body, $internalOnly, $anchorType, $anchor, $parentId, $suggestedEdit, $isReply) {
            $comment = $post->comments()->create([
                'parent_id' => $parentId,
                'user_id' => $user->id,
                'body' => $body,
                'anchor_type' => $isReply ? null : $anchorType,
                'anchor' => $isReply ? null : $anchor,
                'suggested_edit' => $isReply ? null : $suggestedEdit,
                'internal_only' => $user->role === Role::ClientReviewer ? false : $internalOnly,
            ]);

            $mentionedUsers = $this->resolveMentionedUsers($post, $body, $user);

            if ($mentionedUsers->isNotEmpty()) {
                $comment->mentionedUsers()->sync($mentionedUsers->pluck('id'));
            }

            return [$comment, $mentionedUsers];
        });

        [$comment, $mentionedUsers] = $comment;

        Log::info('Post comment created', [
            'post_id' => $post->id,
            'user_id' => $user->id,
            'internal_only' => $comment->internal_only,
            'mentioned_user_ids' => $mentionedUsers->pluck('id')->all(),
        ]);

        $this->notifyMentionedUsers($post, $comment, $mentionedUsers);
        $this->notifyOtherSide($post, $comment, $user, $mentionedUsers);

        return $comment;
    }

    /**
     * Resolve the `@[Name](id)` tokens in a comment body to real, mentionable users for this
     * post (org users or the post's client-portal users), ignoring the author and any ID that
     * doesn't resolve to an eligible user — mention tokens are user-supplied and must never be
     * trusted at face value.
     *
     * @return Collection<int, User>
     */
    private function resolveMentionedUsers(Post $post, string $body, User $author): Collection
    {
        $mentionedIds = CommentMentionParser::extractUserIds($body);

        if ($mentionedIds === []) {
            return collect();
        }

        return $this->notifications->mentionableRecipients($post, exclude: $author)
            ->whereIn('id', $mentionedIds);
    }

    /**
     * Notify every `@`-mentioned user directly, regardless of which "side" they're on.
     *
     * @param  Collection<int, User>  $mentionedUsers
     */
    private function notifyMentionedUsers(Post $post, PostComment $comment, Collection $mentionedUsers): void
    {
        foreach ($mentionedUsers as $recipient) {
            $recipient->notify(new PostCommentMentioned(
                $post,
                $comment,
                forPortal: $recipient->role === Role::ClientReviewer,
            ));
        }
    }

    /**
     * Notify whichever side of the conversation didn't write this comment — org users when a
     * client reviewer comments, or (unless internal-only) client-portal users when an org user
     * comments — so a new comment surfaces in the notification bell for the people who need to
     * see it. Users already notified via an `@`-mention are excluded to avoid a duplicate alert.
     *
     * @param  Collection<int, User>  $mentionedUsers
     */
    private function notifyOtherSide(Post $post, PostComment $comment, User $author, Collection $mentionedUsers): void
    {
        $mentionedIds = $mentionedUsers->pluck('id');

        if ($author->role === Role::ClientReviewer) {
            $this->notifications->send(
                $this->notifications->orgRecipients($post)->whereNotIn('id', $mentionedIds),
                new PostCommentAdded($post, $comment, forPortal: false),
            );

            return;
        }

        if ($comment->internal_only) {
            return;
        }

        $this->notifications->send(
            $this->notifications->clientPortalRecipients($post)->whereNotIn('id', $mentionedIds),
            new PostCommentAdded($post, $comment, forPortal: true),
        );
    }
}
