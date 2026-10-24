<?php

namespace Tests\Feature\Inbox\Concerns;

use App\Contracts\CommentSource;
use App\Exceptions\Inbox\CommentSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Inbox\CommentReplyResult;
use App\Support\Inbox\CommentThreadReading;
use Carbon\CarbonInterface;

/**
 * Hands out queued comment readings per target and records every read and reply, so a test can
 * assert what was fetched and — crucially (M-9.3) — that drafting never sent a reply.
 */
class FakeCommentSource implements CommentSource
{
    /** @var list<int> */
    public array $fetchCalls = [];

    /** @var list<array{account_id: int, parent: string, body: string}> */
    public array $replyCalls = [];

    /**
     * @param  array<int, CommentThreadReading>  $readings  keyed by post_target id
     */
    public function __construct(
        private array $readings = [],
        private ?string $error = null,
        private ?string $replyError = null,
    ) {}

    public function key(): string
    {
        return 'fake';
    }

    public function fetchComments(PostTarget $target, ?CarbonInterface $since = null): CommentThreadReading
    {
        $this->fetchCalls[] = $target->id;

        if ($this->error !== null) {
            throw new CommentSourceException($this->error);
        }

        return $this->readings[$target->id] ?? CommentThreadReading::empty();
    }

    public function reply(SocialAccount $account, string $parentCommentId, string $body): CommentReplyResult
    {
        $this->replyCalls[] = ['account_id' => $account->id, 'parent' => $parentCommentId, 'body' => $body];

        if ($this->replyError !== null) {
            throw new CommentSourceException($this->replyError);
        }

        return new CommentReplyResult(
            externalId: 'reply-'.count($this->replyCalls),
            vendorCommentId: 'reply-'.count($this->replyCalls),
            deliveredAt: now(),
        );
    }

    public function setReading(int $targetId, CommentThreadReading $reading): void
    {
        $this->readings[$targetId] = $reading;
    }
}
