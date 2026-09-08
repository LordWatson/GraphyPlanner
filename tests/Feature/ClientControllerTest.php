<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_clients_list(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->get(route('clients.index'));

        $response->assertOk();
    }

    public function test_designer_is_denied_from_creating_a_client(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();

        $response = $this->actingAs($designer)->get(route('clients.create'));
        $response->assertForbidden();

        $response = $this->actingAs($designer)->post(route('clients.store'), [
            'name' => 'Acme Inc',
            'status' => 'active',
        ]);
        $response->assertForbidden();
    }

    public function test_owner_can_create_a_client_through_the_ui(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->post(route('clients.store'), [
            'name' => 'Acme Inc',
            'status' => 'active',
            'retainer_amount' => 1500,
            'billing_cycle' => 'monthly',
        ]);

        $client = Client::firstWhere('name', 'Acme Inc');

        $response->assertRedirect(route('clients.show', $client));
        $this->assertNotNull($client);
        $this->assertSame($org->id, $client->org_id);
    }

    public function test_owner_can_update_and_list_clients_through_the_ui(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['name' => 'Old Name']);

        $response = $this->actingAs($owner)->put(route('clients.update', $client), [
            'name' => 'New Name',
            'status' => 'active',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertSame('New Name', $client->fresh()->name);
    }

    public function test_client_reviewer_cannot_see_billing_fields_when_viewing_their_client(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create([
            'retainer_amount' => 5000,
        ]);
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('client.retainer_amount', null));
    }

    public function test_client_reviewer_cannot_view_another_clients_page(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $client->id,
        ]);

        $response = $this->actingAs($reviewer)->get(route('clients.show', $otherClient));

        $response->assertForbidden();
    }
}
