<?php

namespace App\Support\Inbox;

use Carbon\CarbonInterface;

/**
 * Step M-9.1 — one public comment as a `CommentSource` read it, normalised across networks. The
 * `externalId` is the network's own comment id (how the inbox dedupes); `parentExternalId` links a
 * reply to the comment it answers, so threads rebuild. Bodies are stored as given but scrubbed
 * before they ever reach a log.
 */
final readonly class InboundComment
{
    public function __construct(
        public string $externalId,
        public string $body,
        public ?string $parentExternalId = null,
        public ?string $authorName = null,
        public ?string $authorHandle = null,
        public ?string $authorAvatarUrl = null,
        public ?string $permalink = null,
        public ?CarbonInterface $postedAt = null,
    ) {}

    public function isReply(): bool
    {
        return $this->parentExternalId !== null && $this->parentExternalId !== '';
    }
}
