<?php

namespace Tests\Feature\Posts;

use App\Contracts\PublishAdapter;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Step I-1.5 — `DELETE posts/{post}` soft-deletes a post that never went out; anything published
 * can only be archived (through the regular transition).
 */
class PostDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_owners_and_strategists_can_delete_a_draft(): void
    {
        foreach ([Role::Owner, Role::Strategist] as $role) {
            $post = $this->makePost(PostStatus::Draft);

            $this->actingAs($this->user($role))
                ->delete(route('posts.destroy', $post))
                ->assertRedirect(route('clients.posts.index', $this->client));

            $this->assertSoftDeleted($post);
        }
    }

    public function test_other_roles_cannot_delete_posts(): void
    {
        $post = $this->makePost(PostStatus::Draft);
        $portalUser = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->client->id]);
        $outsider = User::factory()->for(Organization::factory(), 'organization')->role(Role::Owner)->create();

        foreach ([$this->user(Role::Designer), $this->user(Role::Viewer), $portalUser, $outsider] as $user) {
            // A client reviewer is redirected to their portal (302, Step M-6.2); the others → 403.
            $status = $this->actingAs($user)->delete(route('posts.destroy', $post))->getStatusCode();
            $this->assertContains($status, [302, 403]);
        }

        $this->assertNotNull($post->fresh());
        $this->assertFalse($post->fresh()->trashed());
    }

    public function test_published_posts_can_only_be_archived(): void
    {
        $owner = $this->user(Role::Owner);

        foreach ([PostStatus::Published, PostStatus::PartiallyPublished, PostStatus::Publishing] as $status) {
            $post = $this->makePost($status);

            $this->actingAs($owner)->delete(route('posts.destroy', $post))->assertForbidden();
            $this->assertNotNull(Post::find($post->id), $status->value);
        }

        $published = $this->makePost(PostStatus::Published);
        $this->actingAs($owner)
            ->post(route('posts.transition', $published), ['to' => PostStatus::Archived->value])
            ->assertRedirect(route('posts.edit', $published));
        $this->assertSame(PostStatus::Archived, $published->fresh()->status);
    }

    public function test_a_failed_post_with_a_published_target_cannot_be_deleted(): void
    {
        $post = $this->makePost(PostStatus::Failed);
        $account = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id]);
        $post->targets()->create(['social_account_id' => $account->id, 'status' => PostTargetStatus::Published]);

        $this->actingAs($this->user(Role::Owner))->delete(route('posts.destroy', $post))->assertForbidden();
    }

    public function test_deleting_a_scheduled_post_stops_its_queued_publish_job(): void
    {
        $post = $this->makePost(PostStatus::Scheduled);
        $post->update(['publish_dispatch_id' => 'dispatch-1']);

        $this->actingAs($this->user(Role::Owner))->delete(route('posts.destroy', $post));

        $adapter = Mockery::mock(PublishAdapter::class);
        $adapter->shouldNotReceive('publish');
        $this->app->instance(PublishAdapter::class, $adapter);

        $this->app->call([new PublishPostJob($post->id, 'dispatch-1'), 'handle']);

        $this->assertSoftDeleted($post);
    }

    public function test_the_editor_only_offers_delete_when_allowed(): void
    {
        $owner = $this->user(Role::Owner);

        $this->actingAs($owner)->get(route('posts.edit', $this->makePost(PostStatus::Draft)))
            ->assertInertia(fn ($page) => $page->where('can.delete', true));
        $this->actingAs($owner)->get(route('posts.edit', $this->makePost(PostStatus::Published)))
            ->assertInertia(fn ($page) => $page->where('can.delete', false));
    }

    private function user(Role $role): User
    {
        return User::factory()->for($this->org, 'organization')->role($role)->create();
    }

    private function makePost(PostStatus $status): Post
    {
        return Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => $status]);
    }
}
