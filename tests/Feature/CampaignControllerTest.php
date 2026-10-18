<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_campaign_for_a_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.campaigns.store', $client), [
            'name' => 'Summer launch',
            'start_date' => now()->toDateString(),
        ]);

        $campaign = Campaign::query()->where('client_id', $client->id)->firstOrFail();
        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertDatabaseHas('campaigns', [
            'client_id' => $client->id,
            'org_id' => $org->id,
            'name' => 'Summer launch',
            'status' => CampaignStatus::Planning->value,
        ]);
    }

    public function test_designer_cannot_create_a_campaign(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.campaigns.store', $client), [
            'name' => 'Summer launch',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_user_from_another_org_cannot_create_a_campaign(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->post(route('clients.campaigns.store', $client), [
            'name' => 'Summer launch',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_campaign_requires_a_name(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.campaigns.store', $client), []);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_owner_can_update_a_campaign(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        // Step C-0.3: status is ignored here — it only changes through `campaigns.transition`.
        $response = $this->actingAs($owner)->put(route('campaigns.update', $campaign), [
            'name' => 'Updated name',
            'status' => CampaignStatus::Active->value,
        ]);

        $response->assertRedirect(route('campaigns.show', $campaign));
        $campaign->refresh();
        $this->assertSame('Updated name', $campaign->name);
        $this->assertSame(CampaignStatus::Planning, $campaign->status);
        $this->assertDatabaseHas('campaign_activity_logs', ['campaign_id' => $campaign->id, 'event' => 'campaign.updated']);
    }

    public function test_owner_can_delete_a_campaign(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('campaigns.destroy', $campaign));

        $response->assertRedirect(route('clients.campaigns.index', $client));
        $this->assertSoftDeleted($campaign);
    }

    public function test_client_reviewer_sees_campaigns_scoped_to_their_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Campaign::factory()->for($client)->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.campaigns.index', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('campaigns.data', 1));
    }

    public function test_viewer_can_view_a_campaign_show_page_with_its_posts(): void
    {
        $org = Organization::factory()->create();
        $viewer = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'campaign_id' => $campaign->id]);

        $response = $this->actingAs($viewer)->get(route('campaigns.show', $campaign));

        // Step C-1.2: the show page is the workspace's Overview tab; the posts live on the board.
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('campaigns/overview')
            ->where('campaign.id', $campaign->id)
            ->where('counts.posts', 1)
            ->where('can.update', false)
        );
    }

    public function test_designer_cannot_view_the_campaign_edit_page(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($designer)->get(route('campaigns.edit', $campaign));

        $response->assertForbidden();
    }

    public function test_owner_can_view_the_campaign_edit_page(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('campaigns.edit', $campaign));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('campaign.id', $campaign->id));
    }

    public function test_client_reviewer_cannot_view_another_clients_campaign(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($otherClient, 'client')->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        // Step C-1.2: client contacts are sent to the portal, which only lists their own client's
        // campaigns — never another client's.
        $response = $this->actingAs($reviewer)->get(route('campaigns.show', $campaign));

        $response->assertRedirect(route('portal.campaigns.index'));
        $this->actingAs($reviewer)->get(route('portal.campaigns.show', $campaign))->assertNotFound();
    }
}
