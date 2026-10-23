<?php

namespace Tests\Feature\Content;

use App\Enums\OrganizationType;
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

/**
 * Step M-5.1 — the content hub (`/content`): the org-wide table and status board, its filters, its
 * role gating and its tenant isolation.
 */
class ContentHubTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            ...$attributes,
        ]);
    }

    private function target(Post $post, Platform $platform): void
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
        ]);

        $post->targets()->create(['social_account_id' => $account->id]);
    }

    public function test_staff_see_the_table_of_every_clients_posts(): void
    {
        $this->makePost(['status' => PostStatus::Draft, 'master_caption' => 'Hello world']);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('content/index')
                ->where('view', 'table')
                ->has('posts.data', 1)
                ->where('posts.data.0.master_caption', 'Hello world'));
    }

    public function test_a_client_reviewer_is_redirected_to_the_portal(): void
    {
        $reviewer = User::factory()
            ->for($this->org, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $this->client->id]);

        $this->actingAs($reviewer)
            ->get(route('content.index'))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_a_business_org_is_redirected_to_its_own_posts(): void
    {
        $businessOrg = Organization::factory()->create(['type' => OrganizationType::Business]);
        $member = User::factory()->for($businessOrg, 'organization')->role(Role::Owner)->create();

        $this->actingAs($member)
            ->get(route('content.index'))
            ->assertRedirect(route('business.posts.index'));
    }

    public function test_filters_narrow_the_list(): void
    {
        $instagramPost = $this->makePost(['status' => PostStatus::Draft]);
        $this->target($instagramPost, Platform::Instagram);

        $linkedInPost = $this->makePost(['status' => PostStatus::Approved]);
        $this->target($linkedInPost, Platform::LinkedIn);

        $this->actingAs($this->owner)
            ->get(route('content.index', ['status' => 'draft']))
            ->assertInertia(fn ($page) => $page->has('posts.data', 1)
                ->where('posts.data.0.id', $instagramPost->id));

        $this->actingAs($this->owner)
            ->get(route('content.index', ['platform' => 'linkedin']))
            ->assertInertia(fn ($page) => $page->has('posts.data', 1)
                ->where('posts.data.0.id', $linkedInPost->id));
    }

    public function test_archived_posts_are_hidden_unless_asked_for(): void
    {
        $this->makePost(['status' => PostStatus::Archived]);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertInertia(fn ($page) => $page->has('posts.data', 0));

        $this->actingAs($this->owner)
            ->get(route('content.index', ['status' => 'archived']))
            ->assertInertia(fn ($page) => $page->has('posts.data', 1));
    }

    public function test_another_orgs_posts_never_appear(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        Post::factory()->for($otherClient)->create(['org_id' => $otherOrg->id]);

        $this->actingAs($this->owner)
            ->get(route('content.index'))
            ->assertInertia(fn ($page) => $page->has('posts.data', 0));
    }

    public function test_the_board_offers_only_legal_drop_targets_for_the_role(): void
    {
        $this->makePost(['status' => PostStatus::Draft]);

        // An owner can move a draft on.
        $this->actingAs($this->owner)
            ->get(route('content.index', ['view' => 'board']))
            ->assertInertia(fn ($page) => $page
                ->where('view', 'board')
                ->has('board.columns')
                ->where('board.columns', fn ($columns) => collect($columns)
                    ->firstWhere('key', 'draft')['posts'][0]['drop_targets'] !== []));

        // A viewer can see the board but move nothing.
        $viewer = User::factory()->for($this->org, 'organization')->role(Role::Viewer)->create();

        $this->actingAs($viewer)
            ->get(route('content.index', ['view' => 'board']))
            ->assertInertia(fn ($page) => $page
                ->where('board.columns', fn ($columns) => collect($columns)
                    ->firstWhere('key', 'draft')['posts'][0]['drop_targets'] === []));
    }

    public function test_the_csv_export_streams_the_filtered_posts(): void
    {
        $this->makePost(['status' => PostStatus::Draft, 'master_caption' => 'Exported caption']);

        $response = $this->actingAs($this->owner)->get(route('content.csv'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Exported caption', $response->streamedContent());
    }
}
