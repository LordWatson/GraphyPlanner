<?php

namespace Tests\Feature\Inbox;

use App\Contracts\CommentSource;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\Role;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\Feature\Inbox\Concerns\FakeCommentSource;
use Tests\TestCase;

class InboxReplyTest extends TestCase
{
    use BuildsInboxFixtures, RefreshDatabase;

    public function test_a_human_send_writes_one_outbound_message_and_calls_the_vendor_once(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $conversation = $this->conversation($org, attributes: ['is_reply_needed' => true]);

        $source = new FakeCommentSource;
        $this->app->instance(CommentSource::class, $source);

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.reply', $conversation), ['body' => 'Yes, we ship to Dubai!'])
            ->assertOk();

        $this->assertCount(1, $source->replyCalls);
        $this->assertSame('Yes, we ship to Dubai!', $source->replyCalls[0]['body']);

        $outbound = $conversation->messages()->where('direction', MessageDirection::Outbound)->get();
        $this->assertCount(1, $outbound);
        $this->assertSame(MessageDeliveryStatus::Sent, $outbound->first()->delivery_status);
        $this->assertSame($user->id, $outbound->first()->sent_by);

        $conversation->refresh();
        $this->assertFalse($conversation->is_reply_needed);
        $this->assertNotNull($conversation->first_response_at);
    }

    public function test_a_vendor_failure_records_a_failed_message_and_returns_422(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        $this->app->instance(CommentSource::class, new FakeCommentSource(replyError: 'The network rejected the reply.'));

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.reply', $conversation), ['body' => 'Hello'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The network rejected the reply.']);

        $message = $conversation->messages()->where('direction', MessageDirection::Outbound)->firstOrFail();
        $this->assertSame(MessageDeliveryStatus::Failed, $message->delivery_status);
        $this->assertNotNull($message->delivery_error);
    }

    public function test_sending_a_saved_reply_increments_its_usage(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);
        $reply = SavedReply::factory()->create(['org_id' => $org->id]);

        $this->app->instance(CommentSource::class, new FakeCommentSource);

        $this->actingAs($user)
            ->postJson(route('inbox.conversations.reply', $conversation), ['body' => $reply->body, 'saved_reply_id' => $reply->id])
            ->assertOk();

        $this->assertSame(1, $reply->fresh()->usage_count);
    }

    public function test_a_client_reviewer_cannot_reply(): void
    {
        $org = $this->inboxOrg();
        $client = $this->inboxClient($org);
        $reviewer = User::factory()->create(['org_id' => $org->id, 'role' => Role::ClientReviewer, 'client_id' => $client->id]);
        $conversation = $this->conversation($org, $client);

        $this->app->instance(CommentSource::class, new FakeCommentSource);

        // Reviewers are redirected out of the internal workspace by `portal.redirect`.
        $this->actingAs($reviewer)
            ->post(route('inbox.conversations.reply', $conversation), ['body' => 'Hi'])
            ->assertRedirect();
    }
}
