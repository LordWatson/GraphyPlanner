<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step B-1.3 / B-1.5 — the shared `workspace` Inertia prop the frontend shell reads.
 */
class WorkspaceSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_prop_has_no_brand_and_agency_role_labels(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page
                ->where('workspace.type', 'agency')
                ->where('workspace.brand', null)
                ->where('workspace.roleLabels.strategist', 'Strategist')
                ->where('workspace.roleLabels.designer', 'Designer'));
    }

    public function test_business_prop_exposes_the_primary_brand_and_relabelled_roles(): void
    {
        $organization = Organization::factory()->business()->create();
        $brand = (new CreatePrimaryBrandAction)($organization, name: 'Jess’s Bakery');
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('calendar'))
            ->assertInertia(fn ($page) => $page
                ->where('workspace.type', 'business')
                ->where('workspace.brand.id', $brand->id)
                ->where('workspace.brand.name', 'Jess’s Bakery')
                ->where('workspace.roleLabels.strategist', 'Manager')
                ->where('workspace.roleLabels.designer', 'Creator'));
    }
}
