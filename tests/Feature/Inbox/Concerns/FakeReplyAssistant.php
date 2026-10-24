<?php

namespace Tests\Feature\Inbox\Concerns;

use App\Contracts\ReplyAssistant;
use App\Models\Client;
use App\Models\InboxConversation;

/**
 * Returns a canned draft and records how many times it was asked — so a test can assert the draft
 * endpoint called the model but never sent anything.
 */
class FakeReplyAssistant implements ReplyAssistant
{
    public int $calls = 0;

    public function __construct(private readonly string $draft = 'Thanks for reaching out! We ship worldwide.') {}

    public function draftReply(Client $client, InboxConversation $conversation, ?string $instruction = null): string
    {
        $this->calls++;

        return $this->draft;
    }
}
