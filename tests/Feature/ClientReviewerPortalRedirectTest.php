<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `RedirectClientReviewersToPortal`: client portal contacts are kept inside `/portal/*`, while
 * every staff role reaches the internal routes exactly as before.
 */
class ClientReviewerPortalRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);
    }

    public function test_needs_attention_redirects_to_the_portal_home_instead_of_403ing(): void
    {
        $this->actingAs($this->reviewer)
            ->get(route('needs-attention'))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_another_clients_post_redirects_to_the_portal_post_list(): void
    {
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $otherPost = Post::factory()->for($otherClient)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        $this->actingAs($this->reviewer)
            ->get(route('posts.edit', $otherPost))
            ->assertRedirect(route('portal.posts.index'));
    }

    public function test_staff_roles_are_not_redirected(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('calendar'))->assertOk();
        $this->actingAs($owner)->get(route('needs-attention'))->assertOk();
        $this->actingAs($owner)->get(route('posts.edit', $post))->assertOk();
    }
}
