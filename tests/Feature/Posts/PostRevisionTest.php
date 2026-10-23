<?php

namespace Tests\Feature\Posts;

use App\Actions\Posts\TransitionPostStatusAction;
use App\Actions\Posts\UpdatePostAction;
use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step M-5.3 — a post keeps a content history: a revision per content-changing save, a shared
 * checkpoint when it goes to the client, and a restore that brings old copy back without touching
 * the schedule.
 */
class PostRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['default_approval_mode' => ApprovalMode::InternalOnly]);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function draftWithTarget(): Post
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
            'approval_mode' => ApprovalMode::InternalOnly,
        ]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDays(3)->toDateString(),
            'scheduled_local_time' => '09:00:00',
        ]);

        return $post;
    }

    public function test_a_content_changing_save_records_a_revision_and_a_noop_does_not(): void
    {
        $post = $this->draftWithTarget();
        $update = app(UpdatePostAction::class);

        $update($post, ['master_caption' => 'First version'], $this->owner);
        $this->assertSame(1, $post->revisions()->count());

        // An identical save records nothing.
        $update($post->refresh(), ['master_caption' => 'First version'], $this->owner);
        $this->assertSame(1, $post->revisions()->count());

        // A real change records another.
        $update($post->refresh(), ['master_caption' => 'Second version'], $this->owner);
        $this->assertSame(2, $post->revisions()->count());
        $this->assertSame($this->owner->id, $post->revisions()->latest('id')->first()->created_by);
    }

    public function test_entering_waiting_client_records_a_shared_revision(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::InternalReview,
            'approval_mode' => ApprovalMode::ClientRequired,
            'master_caption' => 'Ready for the client',
        ]);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDays(3)->toDateString(),
            'scheduled_local_time' => '09:00:00',
        ]);

        app(TransitionPostStatusAction::class)($post, PostStatus::WaitingClient, $this->owner);

        $shared = $post->revisions()->whereNotNull('shared_at')->get();
        $this->assertCount(1, $shared);
        $this->assertSame('Ready for the client', $shared->first()->snapshot['master_caption']);
    }

    public function test_restoring_brings_back_old_copy_but_keeps_the_schedule(): void
    {
        $post = $this->draftWithTarget();
        $update = app(UpdatePostAction::class);

        $update($post, ['master_caption' => 'The good caption'], $this->owner);
        $firstRevision = $post->revisions()->latest('id')->firstOrFail();
        $originalDate = $post->targets()->first()->scheduled_local_date->toDateString();

        $update($post->refresh(), ['master_caption' => 'A worse caption'], $this->owner);
        $this->assertSame('A worse caption', $post->refresh()->master_caption);

        $this->actingAs($this->owner)
            ->post(route('posts.revisions.restore', [$post, $firstRevision]))
            ->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('The good caption', $post->master_caption);
        // The schedule is untouched by a content restore.
        $this->assertSame($originalDate, $post->targets()->first()->scheduled_local_date->toDateString());
    }

    public function test_the_revisions_endpoint_lists_versions_with_a_diff(): void
    {
        $post = $this->draftWithTarget();
        $update = app(UpdatePostAction::class);
        $update($post, ['master_caption' => 'One'], $this->owner);
        $update($post->refresh(), ['master_caption' => 'Two'], $this->owner);

        $response = $this->actingAs($this->owner)
            ->getJson(route('posts.revisions.index', $post))
            ->assertOk();

        // Newest first; the newest records the caption change One → Two.
        $response->assertJsonPath('revisions.0.diff.caption.from', 'One');
        $response->assertJsonPath('revisions.0.diff.caption.to', 'Two');
        $this->assertCount(2, $response->json('revisions'));
    }

    public function test_a_client_reviewer_cannot_restore(): void
    {
        $reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);
        $post = $this->draftWithTarget();
        app(UpdatePostAction::class)($post, ['master_caption' => 'One'], $this->owner);
        $revision = $post->revisions()->latest('id')->firstOrFail();

        // Step M-6.2 (I-8.1): a reviewer is redirected to their portal before the internal route.
        $this->actingAs($reviewer)
            ->post(route('posts.revisions.restore', [$post, $revision]))
            ->assertRedirect();

        $this->assertSame('One', $post->refresh()->master_caption);
    }
}
