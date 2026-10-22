<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\OrganizationType;
use App\Exceptions\Workspace\WorkspaceMisconfiguredException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CurrentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CurrentWorkspace
    {
        return app(CurrentWorkspace::class);
    }

    public function test_agency_workspace_has_no_brand(): void
    {
        $organization = Organization::factory()->create();
        $this->actingAs(User::factory()->for($organization, 'organization')->create());

        $workspace = $this->resolver();

        $this->assertSame(OrganizationType::Agency, $workspace->type());
        $this->assertTrue($workspace->isAgency());
        $this->assertFalse($workspace->isBusiness());
        $this->assertNull($workspace->brand());
        $this->assertNull($workspace->brandId());
    }

    public function test_business_workspace_resolves_its_primary_brand(): void
    {
        $organization = Organization::factory()->business()->create();
        $brand = (new CreatePrimaryBrandAction)($organization);
        $this->actingAs(User::factory()->for($organization, 'organization')->create());

        $workspace = $this->resolver();

        $this->assertTrue($workspace->isBusiness());
        $this->assertSame($brand->id, $workspace->brand()?->id);
        $this->assertSame($brand->id, $workspace->brandId());
    }

    public function test_brand_is_cached_for_the_request(): void
    {
        $organization = Organization::factory()->business()->create();
        (new CreatePrimaryBrandAction)($organization);
        $this->actingAs(User::factory()->for($organization, 'organization')->create());

        $workspace = $this->resolver();

        $this->assertSame($workspace->brand(), $workspace->brand());
    }

    public function test_misconfigured_business_org_throws_and_logs(): void
    {
        Log::spy();

        $organization = Organization::factory()->business()->create();
        $this->actingAs(User::factory()->for($organization, 'organization')->create());

        try {
            $this->resolver()->brand();
            $this->fail('Expected a WorkspaceMisconfiguredException.');
        } catch (WorkspaceMisconfiguredException $e) {
            $this->assertSame($organization->id, $e->organizationId);
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'no primary brand')
                && $context['org_id'] === $organization->id);
    }

    public function test_no_authenticated_user_resolves_nothing(): void
    {
        $workspace = $this->resolver();

        $this->assertNull($workspace->organization());
        $this->assertNull($workspace->type());
        $this->assertNull($workspace->brand());
    }
}
