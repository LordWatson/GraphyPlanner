<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostCommentAdded;
use App\Notifications\PostCommentMentioned;
use App\Notifications\PostNoteMentioned;
use App\Notifications\PostWaitingForClientReview;
use App\Notifications\PostWaitingForInternalReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_comment_notifies_org_users(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->post(route('posts.comments.store', $post), [
            'body' => 'Please change the caption',
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($owner, PostCommentAdded::class);
        Notification::assertNotSentTo($reviewer, PostCommentAdded::class);
    }

    public function test_a_non_internal_org_comment_notifies_client_portal_users(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => 'Here is the updated draft',
            'internal_only' => false,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($reviewer, PostCommentAdded::class);
    }

    public function test_an_internal_only_org_comment_does_not_notify_client_portal_users(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => 'Internal note',
            'internal_only' => true,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertNotSentTo($reviewer, PostCommentAdded::class);
    }

    public function test_mentioning_a_user_in_a_comment_notifies_them(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => "@[{$strategist->name}]({$strategist->id}) can you take a look?",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($strategist, PostCommentMentioned::class);
        $this->assertTrue($post->comments()->first()->mentionedUsers->contains($strategist));
    }

    public function test_mentioning_a_client_user_from_an_org_comment_notifies_them(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => "Hey @[{$reviewer->name}]({$reviewer->id}), internal note",
            'internal_only' => true,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($reviewer, PostCommentMentioned::class);
        Notification::assertNotSentTo($reviewer, PostCommentAdded::class);
    }

    public function test_an_unmentionable_user_id_in_a_comment_body_is_ignored(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOrg = Organization::factory()->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => "@[{$outsider->name}]({$outsider->id}) not really mentionable",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertNotSentTo($outsider, PostCommentMentioned::class);
        $this->assertCount(0, $post->comments()->first()->mentionedUsers);
    }

    public function test_a_post_entering_waiting_client_notifies_client_portal_users(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::InternalReview]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::WaitingClient->value,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($reviewer, PostWaitingForClientReview::class);
    }

    public function test_a_post_entering_internal_review_notifies_studio_members_except_the_actor(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $otherOrgUser = User::factory()->role(Role::Owner)->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($designer)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo([$owner, $strategist], PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($designer, PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($reviewer, PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($otherOrgUser, PostWaitingForInternalReview::class);
    }

    public function test_the_internal_review_notification_links_to_the_post_editor(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Acme']);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $data = (new PostWaitingForInternalReview($post))->toArray($owner);

        $this->assertSame(route('posts.edit', $post), $data['url']);
        $this->assertSame($post->id, $data['post_id']);
        $this->assertStringContainsString('Acme', $data['body']);
    }

    public function test_moving_a_post_out_of_internal_review_does_not_send_an_internal_review_notification(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::InternalReview]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::Draft->value,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertNotSentTo($strategist, PostWaitingForInternalReview::class);
    }

    public function test_tagging_a_teammate_in_a_transition_note_notifies_them(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
            'comment' => "@[{$strategist->name}]({$strategist->id}) please take this one",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($strategist, PostNoteMentioned::class);
    }

    public function test_a_teammate_tagged_in_a_transition_note_is_not_also_sent_the_broad_status_notification(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $otherStrategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
            'comment' => "@[{$strategist->name}]({$strategist->id}) please take this one",
        ])->assertRedirect(route('posts.edit', $post));

        // The tagged user gets the personal tag, not the duplicate "waiting for internal review".
        Notification::assertSentTo($strategist, PostNoteMentioned::class);
        Notification::assertNotSentTo($strategist, PostWaitingForInternalReview::class);
        // Everyone else in the studio still gets the broad status notification as before.
        Notification::assertSentTo($otherStrategist, PostWaitingForInternalReview::class);
    }

    public function test_a_client_reviewer_cannot_be_tagged_in_a_transition_note(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        // Status-dialog tags are internal — a client-portal user is never pulled in this way.
        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
            'comment' => "@[{$reviewer->name}]({$reviewer->id}) internal only, shouldn't reach you",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertNotSentTo($reviewer, PostNoteMentioned::class);
    }

    public function test_an_unmentionable_user_id_in_a_transition_note_is_ignored(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOrg = Organization::factory()->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
            'comment' => "@[{$outsider->name}]({$outsider->id}) can't be tagged from another org",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertNotSentTo($outsider, PostNoteMentioned::class);
    }

    public function test_tagging_a_teammate_in_an_approval_override_reason_notifies_them(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::InternalReview,
            'approval_mode' => ApprovalMode::ClientRequired,
        ]);

        $this->actingAs($owner)->post(route('posts.override-approval', $post), [
            'reason' => "@[{$strategist->name}]({$strategist->id}) approved on our call",
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($strategist, PostNoteMentioned::class);
    }

    public function test_the_transition_tag_notification_links_to_the_post_editor(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Acme']);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $data = (new PostNoteMentioned($post, PostStatus::InternalReview, $owner))
            ->toArray($strategist);

        $this->assertSame(route('posts.edit', $post), $data['url']);
        $this->assertSame($post->id, $data['post_id']);
        $this->assertStringContainsString('Acme', $data['body']);
    }

    public function test_a_client_approval_decision_notifies_org_users(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $this->actingAs($reviewer)->post(route('posts.transition', $post), [
            'to' => PostStatus::Approved->value,
        ])->assertRedirect(route('posts.edit', $post));

        Notification::assertSentTo($owner, PostApprovalDecided::class);
    }

    public function test_clicking_a_notification_marks_it_as_read_and_redirects_to_its_url(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $owner->notify(new PostApprovalDecided($post, ApprovalDecision::Approved));

        $notification = $owner->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);

        $response = $this->actingAs($owner)->post(route('notifications.read', $notification->id));

        $response->assertRedirect(route('posts.edit', $post));
        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $otherOwner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $owner->notify(new PostApprovalDecided($post, ApprovalDecision::Approved));
        $notification = $owner->notifications()->firstOrFail();

        $this->actingAs($otherOwner)->post(route('notifications.read', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->refresh()->read_at);
    }
}
