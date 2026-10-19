<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Exceptions\Posts\InvalidPostTransitionException;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step I-3.8 / M-2.9 — `client_required` posts are only approved through the client; an Owner
 * can override with a reason, which is recorded; each client has a default mode.
 */
class ApprovalModeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    private User $strategist;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->strategist = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
    }

    private function postInReview(ApprovalMode $mode): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::InternalReview,
            'approval_mode' => $mode,
        ]);
    }

    public function test_an_internal_only_post_can_be_approved_straight_from_internal_review(): void
    {
        $post = $this->postInReview(ApprovalMode::InternalOnly);

        $this->actingAs($this->strategist)->post(route('posts.transition', $post), ['to' => 'approved'])->assertSessionHasNoErrors();

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_a_client_required_post_must_go_through_the_client(): void
    {
        $post = $this->postInReview(ApprovalMode::ClientRequired);

        $this->actingAs($this->owner)->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page
                ->where('allowedTransitions', fn ($moves) => collect($moves)->doesntContain('value', 'approved'))
                ->where('can.override_approval', true));

        $this->actingAs($this->owner)->post(route('posts.transition', $post), ['to' => 'approved'])
            ->assertSessionHasErrors(['to' => 'This post needs the client’s approval — send it to the client, or an Owner can override.']);
        $this->assertSame(PostStatus::InternalReview, $post->refresh()->status);

        $this->expectException(InvalidPostTransitionException::class);
        app(TransitionPostStatusAction::class)($post, PostStatus::Approved, $this->owner);
    }

    public function test_the_client_route_to_approval_still_works(): void
    {
        $post = $this->postInReview(ApprovalMode::ClientRequired);
        $transition = app(TransitionPostStatusAction::class);

        $transition($post, PostStatus::WaitingClient, $this->strategist);
        $transition($post, PostStatus::Approved, null);

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_an_owner_can_override_with_a_reason_that_is_recorded(): void
    {
        $post = $this->postInReview(ApprovalMode::ClientRequired);

        $this->actingAs($this->owner)->post(route('posts.override-approval', $post), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)->post(route('posts.override-approval', $post), ['reason' => 'Client approved it on our call.'])
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $log = $post->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame('Approval override: Client approved it on our call.', $log->note);
        $this->assertSame($this->owner->id, $log->user_id);
        $approval = $post->approvals()->firstOrFail();
        $this->assertSame(ApprovalDecision::Approved, $approval->decision);
        $this->assertSame($this->owner->id, $approval->user_id);
    }

    public function test_only_owners_can_override_and_only_where_it_applies(): void
    {
        $post = $this->postInReview(ApprovalMode::ClientRequired);

        $this->actingAs($this->strategist)->post(route('posts.override-approval', $post), ['reason' => 'Because we said so'])->assertForbidden();

        $internal = $this->postInReview(ApprovalMode::InternalOnly);
        $this->actingAs($this->owner)->post(route('posts.override-approval', $internal), ['reason' => 'Nothing to override'])->assertForbidden();

        $draft = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft, 'approval_mode' => ApprovalMode::ClientRequired]);
        $this->actingAs($this->owner)->post(route('posts.override-approval', $draft), ['reason' => 'Not in review yet'])->assertForbidden();
    }

    public function test_new_posts_take_the_clients_default_approval_mode(): void
    {
        $this->assertSame(ApprovalMode::ClientRequired, $this->client->refresh()->default_approval_mode, 'client_required is the default.');

        $this->client->update(['default_approval_mode' => ApprovalMode::InternalOnly]);

        $this->actingAs($this->owner)->post(route('clients.posts.store', $this->client), ['master_caption' => 'Hello'])->assertRedirect();

        $this->assertSame(ApprovalMode::InternalOnly, Post::query()->latest('id')->firstOrFail()->approval_mode);
    }
}
