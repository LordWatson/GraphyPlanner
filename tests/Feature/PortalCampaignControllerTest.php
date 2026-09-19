<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client portal's campaigns list/detail — a read-only counterpart to the internal
 * `CampaignController`, scoped to the logged-in contact's own `client_id` by
 * `EnsureClientPortalAccess`, giving a client contact an accessible list of the posts attached to
 * each of their campaigns.
 */
class PortalCampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewer(Client $client): User
    {
        return User::factory()
            ->for($client->organization, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);
    }

    public function test_a_client_reviewer_sees_their_own_campaigns_on_the_list(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);
        Post::factory()->for($client)->create(['org_id' => $org->id, 'campaign_id' => $campaign->id, 'status' => PostStatus::WaitingClient]);
        Post::factory()->for($client)->create(['org_id' => $org->id, 'campaign_id' => $campaign->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($reviewer)->get(route('portal.campaigns.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/campaigns/index')
            ->has('campaigns', 1)
            ->where('campaigns.0.id', $campaign->id)
            ->where('campaigns.0.posts_count', 1)
        );
    }

    public function test_a_client_reviewer_cannot_see_another_clients_campaigns_on_the_list(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);

        Campaign::factory()->for($clientB, 'client')->create(['org_id' => $org->id]);

        $response = $this->actingAs($reviewer)->get(route('portal.campaigns.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('campaigns', 0));
    }

    public function test_a_client_reviewer_can_view_their_own_campaign_with_its_visible_posts(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($client);
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);
        $visiblePost = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'campaign_id' => $campaign->id,
            'status' => PostStatus::WaitingClient,
        ]);
        Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'campaign_id' => $campaign->id,
            'status' => PostStatus::Draft,
        ]);

        $response = $this->actingAs($reviewer)->get(route('portal.campaigns.show', $campaign));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/campaigns/show')
            ->where('campaign.id', $campaign->id)
            ->has('posts', 1)
            ->where('posts.0.id', $visiblePost->id)
        );
    }

    public function test_a_client_reviewer_gets_a_404_viewing_another_clients_campaign(): void
    {
        $org = Organization::factory()->create();
        $clientA = Client::factory()->for($org, 'organization')->create();
        $clientB = Client::factory()->for($org, 'organization')->create();
        $reviewer = $this->makeReviewer($clientA);
        $campaign = Campaign::factory()->for($clientB, 'client')->create(['org_id' => $org->id]);

        $this->actingAs($reviewer)->get(route('portal.campaigns.show', $campaign))->assertNotFound();
    }

    public function test_a_non_reviewer_is_forbidden_from_the_portal_campaigns_list(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('portal.campaigns.index'))->assertForbidden();
    }
}
