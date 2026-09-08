<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
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

        $response->assertRedirect(route('clients.show', $client));
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

        $response = $this->actingAs($owner)->put(route('campaigns.update', $campaign), [
            'name' => 'Updated name',
            'status' => CampaignStatus::Active->value,
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $campaign->refresh();
        $this->assertSame('Updated name', $campaign->name);
        $this->assertSame(CampaignStatus::Active, $campaign->status);
    }

    public function test_owner_can_delete_a_campaign(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $campaign = Campaign::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->delete(route('campaigns.destroy', $campaign));

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    public function test_client_reviewer_sees_campaigns_scoped_to_their_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        Campaign::factory()->for($client)->create(['org_id' => $org->id]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('campaigns', 1));
    }
}
