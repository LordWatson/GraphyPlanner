<?php

namespace Tests\Feature\Posts;

use App\Actions\Campaigns\StartPostFromDeliverableAction;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\ApprovalMode;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\PostWaitingForInternalReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-5.4 — assignment, the internal due date, and targeted internal-review notifications.
 */
class PostAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
    }

    public function test_internal_review_notifies_only_the_reviewer_when_set(): void
    {
        $reviewer = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $bystander = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();

        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
            'approval_mode' => ApprovalMode::InternalOnly,
            'reviewer_id' => $reviewer->id,
        ]);

        app(TransitionPostStatusAction::class)($post, PostStatus::InternalReview, $this->owner);

        Notification::assertSentTo($reviewer, PostWaitingForInternalReview::class);
        Notification::assertNotSentTo($bystander, PostWaitingForInternalReview::class);
    }

    public function test_internal_review_falls_back_to_the_org_when_unassigned(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
            'approval_mode' => ApprovalMode::InternalOnly,
        ]);

        app(TransitionPostStatusAction::class)($post, PostStatus::InternalReview, $this->owner);

        // No assignee/reviewer: every internal user (minus the actor) still hears.
        Notification::assertSentTo($this->designer, PostWaitingForInternalReview::class);
    }

    public function test_an_assignee_and_due_date_save_from_the_editor(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($this->owner)
            ->put(route('posts.update', $post), [
                'master_caption' => 'Owned',
                'assignee_id' => $this->designer->id,
                'due_at' => '2026-12-01',
            ])
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame($this->designer->id, $post->assignee_id);
        $this->assertSame('2026-12-01', $post->due_at?->toDateString());
    }

    public function test_a_user_from_another_org_cannot_be_assigned(): void
    {
        $outsider = User::factory()->for(Organization::factory())->role(Role::Designer)->create();
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($this->owner)
            ->put(route('posts.update', $post), ['assignee_id' => $outsider->id])
            ->assertSessionHasErrors('assignee_id');
    }

    public function test_bulk_assign_from_the_content_hub_sets_the_assignee(): void
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($this->owner)->postJson(route('content.bulk'), [
            'action' => 'assign',
            'post_ids' => [$post->id],
            'value' => $this->designer->id,
        ])->assertOk()->assertJsonPath('done', [$post->id]);

        $this->assertSame($this->designer->id, $post->refresh()->assignee_id);
    }

    public function test_starting_a_post_from_a_deliverable_carries_the_assignee_and_due_date(): void
    {
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::Instagram]);
        $this->client->update(['approval_lead_days' => 2]);

        $deliverable = CampaignDeliverable::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => Platform::Instagram,
            'planned_for' => '2026-12-10',
            'assignee_id' => $this->designer->id,
        ]);

        $post = app(StartPostFromDeliverableAction::class)($deliverable, $this->owner);

        $this->assertSame($this->designer->id, $post->assignee_id);
        // Due two days before the planned date (the client's approval lead time).
        $this->assertSame('2026-12-08', $post->due_at?->toDateString());
    }
}
