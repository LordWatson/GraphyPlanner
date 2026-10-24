<?php

namespace App\Services\Inbox;

use App\Contracts\CommentSource;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Inbox\CommentReplyResult;
use App\Support\Inbox\CommentThreadReading;
use Carbon\CarbonInterface;

/**
 * Step M-9.1 — the safe default when no real comment source is configured: reads nothing and sends
 * nothing, never calling an external service and never throwing. Lets the inbox exist (empty) on an
 * org with no vendor key, and is the double a test binds to assert nothing was sent.
 */
class NullCommentSource implements CommentSource
{
    public function key(): string
    {
        return 'null';
    }

    public function fetchComments(PostTarget $target, ?CarbonInterface $since = null): CommentThreadReading
    {
        return CommentThreadReading::empty();
    }

    public function reply(SocialAccount $account, string $parentCommentId, string $body): CommentReplyResult
    {
        return new CommentReplyResult;
    }
}
