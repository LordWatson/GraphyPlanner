<?php

namespace App\Support\Inbox;

/**
 * Step M-9.1 — every comment a `CommentSource` read for one post target, flat and in vendor order.
 * `IngestCommentsForTargetAction` groups them into conversations by their root comment. Empty when
 * the post has no comments or the platform has no comment source.
 */
final readonly class CommentThreadReading
{
    /**
     * @param  list<InboundComment>  $comments
     */
    public function __construct(public array $comments = []) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->comments === [];
    }

    public function count(): int
    {
        return count($this->comments);
    }
}
