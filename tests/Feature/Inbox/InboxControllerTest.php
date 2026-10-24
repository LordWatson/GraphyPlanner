<?php

namespace Tests\Feature\Inbox;

use App\Enums\CommentSentiment;
use App\Enums\InboxStatus;
use App\Enums\Role;
use App\Models\SavedReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Inbox\Concerns\BuildsInboxFixtures;
use Tests\TestCase;

class InboxControllerTest extends TestCase
{
    use BuildsInboxFixtures, RefreshDatabase;

    public function test_the_inbox_lists_conversations_for_the_org(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $this->conversation($org, attributes: ['is_reply_needed' => true, 'sentiment' => CommentSentiment::Question]);

        $this->actingAs($user)->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('inbox/index')
                ->has('conversations.data', 1)
                ->where('replyNeededCount', 1));
    }

    public function test_a_client_reviewer_cannot_reach_the_inbox(): void
    {
        $org = $this->inboxOrg();
        $client = $this->inboxClient($org);
        $reviewer = User::factory()->create(['org_id' => $org->id, 'role' => Role::ClientReviewer, 'client_id' => $client->id]);

        // `portal.redirect` bounces a reviewer out of the internal workspace.
        $this->actingAs($reviewer)->get(route('inbox.index'))->assertRedirect();
    }

    public function test_assigning_a_conversation(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org, Role::Owner);
        $designer = $this->staff($org, Role::Designer);
        $conversation = $this->conversation($org);

        $this->actingAs($user)
            ->patchJson(route('inbox.conversations.update', $conversation), ['action' => 'assign', 'assigned_to' => $designer->id])
            ->assertOk();

        $this->assertSame($designer->id, $conversation->fresh()->assigned_to);
    }

    public function test_resolving_and_reopening(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $conversation = $this->conversation($org, attributes: ['is_reply_needed' => true]);

        $this->actingAs($user)->patchJson(route('inbox.conversations.update', $conversation), ['action' => 'resolve'])->assertOk();
        $conversation->refresh();
        $this->assertSame(InboxStatus::Resolved, $conversation->status);
        $this->assertFalse($conversation->is_reply_needed);
        $this->assertSame($user->id, $conversation->resolved_by);

        $this->actingAs($user)->patchJson(route('inbox.conversations.update', $conversation), ['action' => 'reopen'])->assertOk();
        $this->assertSame(InboxStatus::Open, $conversation->fresh()->status);
    }

    public function test_snooze_requires_a_future_time(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org);
        $conversation = $this->conversation($org);

        $this->actingAs($user)
            ->patchJson(route('inbox.conversations.update', $conversation), ['action' => 'snooze'])
            ->assertStatus(422);
    }

    public function test_a_viewer_cannot_act(): void
    {
        $org = $this->inboxOrg();
        $viewer = $this->staff($org, Role::Viewer);
        $conversation = $this->conversation($org);

        $this->actingAs($viewer)->get(route('inbox.index'))->assertOk();
        $this->actingAs($viewer)
            ->patchJson(route('inbox.conversations.update', $conversation), ['action' => 'resolve'])
            ->assertForbidden();
    }

    public function test_conversations_are_tenant_isolated(): void
    {
        $orgA = $this->inboxOrg();
        $orgB = $this->inboxOrg();
        $userB = $this->staff($orgB);
        $conversationA = $this->conversation($orgA);

        // A bound conversation from another org reaches the policy and is refused.
        $this->actingAs($userB)->getJson(route('inbox.conversations.show', $conversationA))->assertForbidden();
    }

    public function test_managing_saved_replies(): void
    {
        $org = $this->inboxOrg();
        $user = $this->staff($org, Role::Owner);
        $client = $this->inboxClient($org);

        $this->actingAs($user)->post(route('saved-replies.store'), [
            'title' => 'Shipping', 'body' => 'We ship worldwide!', 'client_id' => $client->id,
        ])->assertRedirect();

        $reply = SavedReply::query()->firstOrFail();
        $this->assertSame($org->id, $reply->org_id);
        $this->assertSame($client->id, $reply->client_id);

        $this->actingAs($user)->delete(route('saved-replies.destroy', $reply))->assertRedirect();
        $this->assertSoftDeleted($reply);
    }

    public function test_a_viewer_cannot_create_a_saved_reply(): void
    {
        $org = $this->inboxOrg();
        $viewer = $this->staff($org, Role::Viewer);

        $this->actingAs($viewer)->post(route('saved-replies.store'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }
}
