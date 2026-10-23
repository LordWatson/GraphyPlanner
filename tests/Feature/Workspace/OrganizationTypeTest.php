<?php

namespace Tests\Feature\Workspace;

use App\Enums\OrganizationType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_orgs_default_to_agency(): void
    {
        $organization = Organization::factory()->create();

        $this->assertSame(OrganizationType::Agency, $organization->fresh()->type);
        $this->assertTrue($organization->isAgency());
        $this->assertFalse($organization->isBusiness());
    }

    public function test_business_factory_state_marks_the_type(): void
    {
        $organization = Organization::factory()->business()->create();

        $this->assertSame(OrganizationType::Business, $organization->fresh()->type);
        $this->assertTrue($organization->isBusiness());
        $this->assertFalse($organization->isAgency());
    }

    public function test_of_type_scope_filters(): void
    {
        $agency = Organization::factory()->create();
        $business = Organization::factory()->business()->create();

        $businessIds = Organization::query()->ofType(OrganizationType::Business)->pluck('id');

        $this->assertTrue($businessIds->contains($business->id));
        $this->assertFalse($businessIds->contains($agency->id));
    }

    public function test_primary_brand_relation_resolves_the_flagged_client(): void
    {
        $organization = Organization::factory()->business()->create();
        Client::factory()->for($organization, 'organization')->create();
        $primary = Client::factory()->for($organization, 'organization')->primary()->create();

        $this->assertNotNull($organization->primaryBrand);
        $this->assertSame($primary->id, $organization->primaryBrand->id);
    }

    public function test_primary_brand_relation_is_null_for_an_agency(): void
    {
        $organization = Organization::factory()->create();
        Client::factory()->for($organization, 'organization')->create();

        $this->assertNull($organization->primaryBrand);
    }
}
