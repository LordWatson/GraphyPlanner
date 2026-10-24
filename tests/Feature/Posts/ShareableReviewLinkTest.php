<?php

namespace Tests\Feature\Posts;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UX overhaul (post-editor journey) — the studio can mint a fresh, copyable review link for a post
 * waiting on the client, to share over any channel. It rotates the post's token like the review
 * email does, and works with no email contact on file.
 */
class ShareableReviewLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_owner_mints_a_working_review_link_and_rotates_old_tokens(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        // A previous, still-valid token that must be turned off once a fresh link is minted.
        $old = ReviewToken::create([
            'token_hash' => hash('sha256', 'old-plain-token'),
            'client_id' => $this->client->id,
            'post_id' => $post->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->owner)->postJson(route('posts.review-link', $post));
        $response->assertOk();

        $url = (string) $response->json('url');
        $this->assertStringContainsString('/review/', $url);

        // The old link is revoked; exactly one fresh, unrevoked token remains for the post.
        $this->assertNotNull($old->fresh()?->revoked_at);
        $this->assertSame(1, ReviewToken::query()
            ->where('post_id', $post->id)
            ->whereNull('revoked_at')
            ->count());

        // The minted link actually opens the public review page.
        $token = str($url)->afterLast('/review/')->toString();
        $this->get(route('review.show', $token))->assertOk();
    }

    public function test_a_post_not_waiting_on_the_client_has_no_link_to_share(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('posts.review-link', $post))
            ->assertStatus(422);
    }

    public function test_a_viewer_from_another_org_cannot_mint_a_link(): void
    {
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'status' => PostStatus::WaitingClient,
        ]);

        $stranger = User::factory()->role(Role::Owner)->create();

        $status = $this->actingAs($stranger)
            ->postJson(route('posts.review-link', $post))
            ->status();

        $this->assertContains($status, [403, 404]);
    }
}
