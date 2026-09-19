<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostCommentAdded;
use App\Notifications\PostCommentMentioned;
use App\Notifications\PostWaitingForClientReview;
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
