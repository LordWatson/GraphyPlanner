<?php

namespace Tests\Feature\Workspace;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\ApprovalMode;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step B-1.4 — `ApprovalMode::ClientRequired` has no meaning in a business workspace (no client to
 * approve), so the post Form Requests reject it there while agencies keep it.
 */
class PostApprovalModeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_org_cannot_set_client_required_approval_on_create(): void
    {
        $organization = Organization::factory()->business()->create();
        $brand = (new CreatePrimaryBrandAction)($organization);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        // Step B-4.1: a business org posts through its brand-implicit shell route (the agency
        // `clients.posts.store` is now closed to it); the `client_required` rejection still applies.
        $this->actingAs($owner)
            ->from(route('business.posts.index'))
            ->post(route('business.posts.store'), ['approval_mode' => ApprovalMode::ClientRequired->value])
            ->assertSessionHasErrors('approval_mode');
    }

    public function test_agency_org_can_set_client_required_approval_on_create(): void
    {
        $owner = User::factory()->role(Role::Owner)->create();
        $client = Client::factory()->for($owner->organization, 'organization')->create();

        $this->actingAs($owner)
            ->post(route('clients.posts.store', $client), ['approval_mode' => ApprovalMode::ClientRequired->value])
            ->assertSessionHasNoErrors();
    }
}
