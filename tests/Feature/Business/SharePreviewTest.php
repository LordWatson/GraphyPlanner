<?php

namespace Tests\Feature\Business;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\ReviewToken;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Step B-3.4 — "Share preview" reuses the review-token machinery for a read-only partner/sponsor
 * link. Decisions are off by default; comments/approval are opt-in; a business post never
 * transitions through the link.
 */
class SharePreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Organization, 1: User, 2: Post}
     */
    private function businessPost(): array
    {
        $organization = Organization::factory()->business()->create();
        $brand = Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $account = SocialAccount::factory()->for($brand)->connected()->create([
            'org_id' => $organization->id,
            'platform' => Platform::Instagram,
        ]);
        $post = Post::factory()->for($brand)->create([
            'org_id' => $organization->id,
            'status' => PostStatus::Approved,
        ]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'scheduled_local_date' => now()->addDay()->toDateString(),
            'scheduled_local_time' => '09:00',
            'scheduled_at_utc' => now()->addDay(),
        ]);

        return [$organization, $owner, $post];
    }

    private function mint(User $owner, Post $post, array $payload = []): string
    {
        $response = $this->actingAs($owner)
            ->postJson(route('posts.share-preview', $post), $payload)
            ->assertOk();

        return $response->json('url');
    }

    public function test_view_only_link_shows_a_read_only_brand_preview(): void
    {
        [$organization, $owner, $post] = $this->businessPost();

        $url = $this->mint($owner, $post);
        $token = ReviewToken::query()->latest('id')->first();
        $this->assertFalse($token->allow_comments);
        $this->assertFalse($token->allow_approval);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('review/show')
                ->where('mode', 'preview')
                ->where('client.name', $post->client->name)
                ->where('post.can_decide', false)
                ->where('post.can_comment', false));
    }

    public function test_comment_only_link_records_a_comment_without_changing_status(): void
    {
        [, $owner, $post] = $this->businessPost();

        $url = $this->mint($owner, $post, ['allow_comments' => true]);
        $token = ReviewToken::query()->latest('id')->first();
        $plain = $this->tokenFromUrl($url);

        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.can_comment', true)
            ->where('post.can_decide', false));

        $this->post(route('review.preview-feedback', $plain), ['comment' => 'Love this one'])
            ->assertRedirect(route('review.show', $plain));

        $this->assertSame(1, $post->comments()->count());
        $this->assertFalse($post->comments()->first()->internal_only);
        $this->assertSame(0, $post->approvals()->count());
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);

        // A decision isn't allowed on a comment-only link.
        $this->post(route('review.preview-feedback', $plain), ['decision' => 'approved'])
            ->assertForbidden();
    }

    public function test_approval_enabled_link_records_a_decision_but_never_transitions(): void
    {
        [, $owner, $post] = $this->businessPost();

        $url = $this->mint($owner, $post, ['allow_approval' => true]);
        $plain = $this->tokenFromUrl($url);

        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('post.can_decide', true));

        $this->post(route('review.preview-feedback', $plain), [
            'decision' => 'approved',
            'comment' => 'Approved for the campaign',
        ])->assertRedirect(route('review.show', $plain));

        $this->assertSame(1, $post->approvals()->count());
        $this->assertNull($post->approvals()->first()->user_id);
        // The whole point: a business post never moves to waiting_client (or anywhere) via a link.
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
        $this->assertNotSame(PostStatus::WaitingClient, $post->status);
    }

    public function test_the_agency_decide_endpoint_rejects_a_business_preview_token(): void
    {
        [, $owner, $post] = $this->businessPost();
        $plain = $this->tokenFromUrl($this->mint($owner, $post, ['allow_approval' => true]));

        $this->post(route('review.decide', $plain), ['decision' => 'approved'])
            ->assertNotFound();
    }

    public function test_an_expired_link_is_404(): void
    {
        [, $owner, $post] = $this->businessPost();
        $this->mint($owner, $post);

        $token = ReviewToken::query()->latest('id')->first();
        $token->update(['expires_at' => now()->subDay()]);

        $this->get(route('review.show', 'anything'))->assertNotFound();
    }

    public function test_only_owners_and_managers_can_mint_a_link(): void
    {
        [$organization, , $post] = $this->businessPost();
        $designer = User::factory()->for($organization, 'organization')->role(Role::Designer)->create();

        $this->actingAs($designer)
            ->postJson(route('posts.share-preview', $post))
            ->assertForbidden();

        $other = User::factory()->role(Role::Owner)->create();
        $this->actingAs($other)
            ->postJson(route('posts.share-preview', $post))
            ->assertForbidden();
    }

    private function tokenFromUrl(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }
}
