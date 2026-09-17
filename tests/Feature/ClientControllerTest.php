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

    public function test_create_and_edit_forms_expose_org_scoped_owner_options(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $otherOrg = Organization::factory()->create();
        $otherOrgUser = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $createResponse = $this->actingAs($owner)->get(route('clients.create'));
        $createResponse->assertOk();
        $createResponse->assertInertia(function ($page) use ($owner, $otherOrgUser) {
            $ownerIds = collect($page->toArray()['props']['owners'])->pluck('value');
            $this->assertTrue($ownerIds->contains((string) $owner->id));
            $this->assertFalse($ownerIds->contains((string) $otherOrgUser->id));

            return $page;
        });

        $editResponse = $this->actingAs($owner)->get(route('clients.edit', $client));
        $editResponse->assertOk();
        $editResponse->assertInertia(function ($page) use ($owner) {
            $ownerIds = collect($page->toArray()['props']['owners'])->pluck('value');
            $this->assertTrue($ownerIds->contains((string) $owner->id));

            return $page;
        });
    }

    public function test_owner_can_assign_a_client_owner_through_the_ui(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $assignee = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create(['owner_user_id' => null]);

        $response = $this->actingAs($owner)->put(route('clients.update', $client), [
            'name' => $client->name,
            'status' => 'active',
            'owner_user_id' => $assignee->id,
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertSame($assignee->id, $client->fresh()->owner_user_id);
    }

    public function test_owner_can_clear_a_client_owner_through_the_ui(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create(['owner_user_id' => $owner->id]);

        $response = $this->actingAs($owner)->put(route('clients.update', $client), [
            'name' => $client->name,
            'status' => 'active',
            'owner_user_id' => '',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertNull($client->fresh()->owner_user_id);
    }

    public function test_owner_cannot_assign_a_user_from_another_organization_as_client_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOrg = Organization::factory()->create();
        $otherOrgUser = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->put(route('clients.update', $client), [
            'name' => $client->name,
            'status' => 'active',
            'owner_user_id' => $otherOrgUser->id,
        ]);

        $response->assertInvalid('owner_user_id');
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
