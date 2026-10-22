<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step B-1.4 — the policy layer that backs the `workspace:agency` route guard: a business org is
 * denied agency-only abilities even if a URL slipped past the middleware.
 */
class WorkspacePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): User
    {
        $organization = Organization::factory()->business()->create();
        (new CreatePrimaryBrandAction)($organization);

        return User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
    }

    public function test_business_owner_cannot_create_clients(): void
    {
        $owner = $this->businessOwner();

        $this->assertFalse($owner->can('create', Client::class));
    }

    public function test_agency_owner_can_still_create_clients(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();

        $this->assertTrue($owner->can('create', Client::class));
    }

    public function test_a_primary_brand_can_never_be_deleted(): void
    {
        $owner = $this->businessOwner();
        $brand = $owner->organization->primaryBrand()->first();

        $this->assertFalse($owner->can('delete', $brand));
    }

    public function test_an_agency_owner_can_delete_a_non_primary_client(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $client = Client::factory()->for($owner->organization, 'organization')->create();

        $this->assertTrue($owner->can('delete', $client));
    }

    public function test_business_owner_is_denied_all_invoice_abilities(): void
    {
        $owner = $this->businessOwner();
        $brand = $owner->organization->primaryBrand()->first();
        $invoice = Invoice::factory()->for($brand)->create(['org_id' => $owner->org_id]);

        $this->assertFalse($owner->can('viewLedger', Invoice::class));
        $this->assertFalse($owner->can('view', $invoice));
        $this->assertFalse($owner->can('create', [Invoice::class, $brand]));
    }

    public function test_business_owner_is_denied_client_invitation_abilities(): void
    {
        $owner = $this->businessOwner();
        $brand = $owner->organization->primaryBrand()->first();
        $invitation = ClientInvitation::factory()->for($brand)->create();

        $this->assertFalse($owner->can('create', [ClientInvitation::class, $brand]));
        $this->assertFalse($owner->can('delete', $invitation));
    }

    public function test_agency_owner_keeps_invoice_and_invitation_abilities(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $client = Client::factory()->for($owner->organization, 'organization')->create();

        $this->assertTrue($owner->can('viewLedger', Invoice::class));
        $this->assertTrue($owner->can('create', [Invoice::class, $client]));
        $this->assertTrue($owner->can('create', [ClientInvitation::class, $client]));
    }
}
