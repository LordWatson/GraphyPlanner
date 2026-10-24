<?php

namespace App\Contracts;

use App\Exceptions\Inbox\CommentSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Inbox\CommentReplyResult;
use App\Support\Inbox\CommentThreadReading;
use Carbon\CarbonInterface;

/**
 * Step M-9.1 — where the social inbox reads comments from, and sends replies to. Bound to
 * `UploadPostCommentSource` (M-0.2: Upload-Post covers seven networks); `NullCommentSource` is the
 * safe no-op. Kept separate from `PublishAdapter` and `EngagementSource` so a Meta Graph fallback
 * (recorded in `publishing.md`) can be swapped in without touching publishing or analytics — and so
 * it does **not** count as a Phase-4 second adapter.
 *
 * `reply()` is the single path to the vendor: `SendInboxReplyAction` is its only caller, and that
 * action only runs from an explicit human request (Phase M-9 exit criterion: a human always sends).
 */
interface CommentSource
{
    /**
     * Stored as `inbox_messages`/conversation provenance, so a reading can be traced to its source.
     */
    public function key(): string;

    /**
     * Read the comments on a published post target. `$since` lets the caller ask only for comments
     * newer than the last sync when the vendor supports it. Returns an empty reading when the
     * platform has no comment source (`config/platforms.php` `comments`) or the post has none.
     *
     * @throws CommentSourceException when the vendor call itself failed
     */
    public function fetchComments(PostTarget $target, ?CarbonInterface $since = null): CommentThreadReading;

    /**
     * Send one reply to a comment, as the account that owns the post. `$parentCommentId` is the
     * network's own id for the comment being answered.
     *
     * @throws CommentSourceException when the vendor refused or could not be reached
     */
    public function reply(SocialAccount $account, string $parentCommentId, string $body): CommentReplyResult;
}
