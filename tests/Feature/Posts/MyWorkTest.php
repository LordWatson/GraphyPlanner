<?php

namespace Tests\Feature\Posts;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step M-5.4 — "My work" shows each staff member their own posts, scoped to their org, and keeps a
 * client reviewer out.
 */
class MyWorkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->designer = User::factory()->for($this->org, 'organization')->role(Role::Designer)->create();
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
            ...$attributes,
        ]);
    }

    public function test_my_work_shows_the_viewers_own_buckets(): void
    {
        $other = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();

        $mine = $this->makePost(['assignee_id' => $this->designer->id]);
        $dueSoon = $this->makePost(['assignee_id' => $this->designer->id, 'due_at' => now()->addDay()]);
        $toReview = $this->makePost(['status' => PostStatus::InternalReview, 'reviewer_id' => $this->designer->id]);
        $someoneElses = $this->makePost(['assignee_id' => $other->id]);

        $this->actingAs($this->designer)
            ->get(route('my-work.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('my-work/index')
                ->where('buckets.due', fn ($rows) => collect($rows)->pluck('id')->contains($dueSoon->id))
                ->where('buckets.review', fn ($rows) => collect($rows)->pluck('id')->contains($toReview->id))
                ->where('buckets.assigned', fn ($rows) => collect($rows)->pluck('id')->contains($mine->id)
                    && ! collect($rows)->pluck('id')->contains($someoneElses->id)
                    // Due-soon posts live in their own bucket, not here.
                    && ! collect($rows)->pluck('id')->contains($dueSoon->id)));
    }

    public function test_the_mentioned_bucket_lists_posts_the_viewer_was_tagged_in(): void
    {
        $author = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $post = $this->makePost();
        $comment = $post->comments()->create([
            'user_id' => $author->id,
            'body' => 'Hey @designer',
            'internal_only' => true,
        ]);
        $comment->mentionedUsers()->attach($this->designer->id);

        $this->actingAs($this->designer)
            ->get(route('my-work.index'))
            ->assertInertia(fn ($page) => $page
                ->where('buckets.mentioned', fn ($rows) => collect($rows)->pluck('id')->contains($post->id)));
    }

    public function test_a_client_reviewer_is_redirected_to_the_portal(): void
    {
        $reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);

        $this->actingAs($reviewer)
            ->get(route('my-work.index'))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_another_orgs_posts_never_appear(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        // Same designer id space, but a different org's post assigned to a different user.
        $otherUser = User::factory()->for($otherOrg, 'organization')->role(Role::Designer)->create();
        Post::factory()->for($otherClient)->create([
            'org_id' => $otherOrg->id,
            'status' => PostStatus::Draft,
            'assignee_id' => $otherUser->id,
        ]);

        $this->actingAs($this->designer)
            ->get(route('my-work.index'))
            ->assertInertia(fn ($page) => $page
                ->where('buckets.assigned', [])
                ->where('buckets.due', []));
    }
}
