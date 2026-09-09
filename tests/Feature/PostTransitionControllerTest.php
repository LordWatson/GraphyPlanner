<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTransitionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_move_a_post_through_the_valid_transition_chain(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        // The §6 checklist must pass before a `scheduled` transition (Step 0.10) — give the post a
        // fully-scheduled target on a text-only-capable platform (LinkedIn) so no media is required.
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::LinkedIn,
        ]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDay(),
        ]);

        $chain = [
            PostStatus::InternalReview,
            PostStatus::WaitingClient,
            PostStatus::Approved,
            PostStatus::Scheduled,
        ];

        foreach ($chain as $to) {
            $response = $this->actingAs($owner)->post(route('posts.transition', $post), [
                'to' => $to->value,
            ]);

            $response->assertRedirect(route('posts.edit', $post));
            $post->refresh();
            $this->assertSame($to, $post->status);
        }

        $this->assertSame(4, $post->activityLogs()->count());
        $this->assertSame(1, $post->approvals()->count());
    }

    public function test_scheduling_is_blocked_when_the_checklist_has_not_passed(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        // No targets and no media attached — the §6 checklist can't pass, per the Phase 0 exit
        // criterion "Schedule blocked without media unless platform is text-only".
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Approved]);

        $response = $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::Scheduled->value,
        ]);

        $response->assertInvalid('to');
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Idea]);

        $response = $this->actingAs($owner)->post(route('posts.transition', $post), [
            'to' => PostStatus::Published->value,
        ]);

        $response->assertInvalid('to');
        $this->assertSame(PostStatus::Idea, $post->refresh()->status);
        $this->assertSame(0, $post->activityLogs()->count());
    }

    public function test_designer_from_another_org_cannot_transition_a_post(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);
        $otherDesigner = User::factory()->for($otherOrg, 'organization')->role(Role::Designer)->create();

        $response = $this->actingAs($otherDesigner)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
        ]);

        $response->assertForbidden();
        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }

    public function test_client_reviewer_can_approve_a_post_waiting_on_them_and_it_creates_an_approval_record(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::WaitingClient]);

        $response = $this->actingAs($reviewer)->post(route('posts.transition', $post), [
            'to' => PostStatus::Approved->value,
            'comment' => 'Looks great!',
        ]);

        $response->assertRedirect(route('posts.edit', $post));
        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);

        $approval = $post->approvals()->firstOrFail();
        $this->assertSame(ApprovalDecision::Approved, $approval->decision);
        $this->assertSame('Looks great!', $approval->comment);
        $this->assertSame($reviewer->id, $approval->user_id);
    }

    public function test_client_reviewer_cannot_transition_a_post_that_is_not_waiting_on_them(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($reviewer)->post(route('posts.transition', $post), [
            'to' => PostStatus::InternalReview->value,
        ]);

        $response->assertForbidden();
        $this->assertSame(PostStatus::Draft, $post->refresh()->status);
    }

    public function test_client_reviewer_cannot_review_another_clients_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($otherClient)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        $response = $this->actingAs($reviewer)->post(route('posts.transition', $post), [
            'to' => PostStatus::Approved->value,
        ]);

        $response->assertForbidden();
    }

    public function test_a_comment_can_be_added_to_a_post_with_an_internal_only_flag(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->post(route('posts.comments.store', $post), [
            'body' => 'Internal note for the team',
            'internal_only' => true,
        ]);

        $response->assertRedirect(route('posts.edit', $post));

        $comment = $post->comments()->firstOrFail();
        $this->assertSame('Internal note for the team', $comment->body);
        $this->assertTrue($comment->internal_only);
        $this->assertSame($owner->id, $comment->user_id);
    }

    public function test_client_reviewer_comments_are_never_stored_as_internal_only(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->post(route('posts.comments.store', $post), [
            'body' => 'Please change the caption',
            'internal_only' => true,
        ])->assertRedirect(route('posts.edit', $post));

        $comment = $post->comments()->firstOrFail();
        $this->assertFalse($comment->internal_only);
    }
}
