<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 6.7 — the client portal's read-only "company" page: the logged-in contact's own client
 * record, with no billing-sensitive fields exposed.
 */
class PortalClientControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeReviewer(Client $client): User
    {
        return User::factory()
            ->for($client->organization, 'organization')
            ->role(Role::ClientReviewer)
            ->create(['client_id' => $client->id]);
    }

    public function test_a_client_reviewer_can_view_their_own_company_record(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create([
            'name' => 'Recommendo',
            'industry' => 'Retail',
            'status' => ClientStatus::Active,
            'retainer_amount' => 2500,
        ]);
        $reviewer = $this->makeReviewer($client);

        $response = $this->actingAs($reviewer)->get(route('portal.company'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('portal/company')
            ->where('client.id', $client->id)
            ->where('client.name', 'Recommendo')
            ->where('client.industry', 'Retail')
            ->where('client.status', 'active')
            ->missing('client.retainer_amount')
            ->missing('client.billing_cycle')
        );
    }

    public function test_a_user_without_a_client_id_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => null]);

        $this->actingAs($reviewer)->get(route('portal.company'))->assertForbidden();
    }

    public function test_a_non_reviewer_cannot_access_the_portal_company_page(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->get(route('portal.company'))->assertForbidden();
    }
}
