<?php

namespace App\Support\Inbox;

use Carbon\CarbonInterface;

/**
 * Step M-9.3 — what the vendor returned after a human sent a reply. `vendorCommentId` /
 * `externalId` are the new comment's ids on the network (so a later sync dedupes it against the
 * message we already stored); `deliveredAt` is when the vendor accepted it.
 */
final readonly class CommentReplyResult
{
    public function __construct(
        public ?string $externalId = null,
        public ?string $vendorCommentId = null,
        public ?CarbonInterface $deliveredAt = null,
    ) {}
}
