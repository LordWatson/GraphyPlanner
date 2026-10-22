<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\ClientStatus;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePrimaryBrandActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_primary_brand_with_an_empty_brand_brain(): void
    {
        $organization = Organization::factory()->business()->create(['name' => 'Jess’s Bakery']);

        $brand = (new CreatePrimaryBrandAction)($organization, defaultLanguage: 'en');

        $this->assertTrue($brand->is_primary);
        $this->assertSame(ClientStatus::Active, $brand->status);
        $this->assertSame('Jess’s Bakery', $brand->name);
        $this->assertSame($organization->id, $brand->org_id);
        $this->assertSame('en', $brand->default_language);
        $this->assertDatabaseHas('brand_brains', ['client_id' => $brand->id]);
        $this->assertNotNull(BrandBrain::where('client_id', $brand->id)->first());
    }

    public function test_an_explicit_name_overrides_the_org_name(): void
    {
        $organization = Organization::factory()->business()->create(['name' => 'Acme Ltd']);

        $brand = (new CreatePrimaryBrandAction)($organization, name: 'Acme Bakehouse');

        $this->assertSame('Acme Bakehouse', $brand->name);
    }

    public function test_the_database_rejects_a_second_primary_brand_for_the_same_org(): void
    {
        $organization = Organization::factory()->business()->create();
        (new CreatePrimaryBrandAction)($organization);

        $this->expectException(QueryException::class);

        (new CreatePrimaryBrandAction)($organization);
    }

    public function test_two_different_orgs_can_each_have_a_primary_brand(): void
    {
        $one = Organization::factory()->business()->create();
        $two = Organization::factory()->business()->create();

        $brandOne = (new CreatePrimaryBrandAction)($one);
        $brandTwo = (new CreatePrimaryBrandAction)($two);

        $this->assertNotSame($brandOne->id, $brandTwo->id);
        $this->assertSame(2, Client::where('is_primary', true)->count());
    }

    public function test_an_ordinary_client_does_not_collide_with_a_primary_brand(): void
    {
        $organization = Organization::factory()->business()->create();
        (new CreatePrimaryBrandAction)($organization);

        // A non-primary client in the same org has primary_org_id = NULL, which never collides.
        $ordinary = Client::factory()->for($organization, 'organization')->create();

        $this->assertFalse($ordinary->is_primary);
        $this->assertSame(2, Client::where('org_id', $organization->id)->count());
    }
}
