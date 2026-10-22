<?php

namespace Tests\Feature\Business;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\Workspace\WorkspaceCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Step B-4.4 — the copy pass. A business workspace never shows agency vocabulary: shared PHP copy
 * is translated (`WorkspaceCopy`), and the business shell pages never carry "retainer" or "invoice".
 */
class WorkspaceCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_terms_are_translated_only_for_business_orgs(): void
    {
        $business = WorkspaceCopy::forOrganization(Organization::factory()->business()->make());
        $agency = WorkspaceCopy::forOrganization(Organization::factory()->make());

        $this->assertSame('brand', $business->term('client'));
        $this->assertSame('Brands', $business->term('Clients'));
        $this->assertSame('collection', $business->term('campaign'));
        $this->assertSame('brand', $business->brandWord());

        // Agencies keep their own words.
        $this->assertSame('client', $agency->term('client'));
        $this->assertSame('campaign', $agency->term('campaign'));
        $this->assertSame('client', $agency->brandWord());

        // Unknown terms pass through unchanged.
        $this->assertSame('sourdough', $business->term('sourdough'));
    }

    public function test_a_null_organization_defaults_to_agency_copy(): void
    {
        $copy = WorkspaceCopy::forOrganization(null);

        $this->assertFalse($copy->isBusiness());
        $this->assertSame('client', $copy->term('client'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function businessPages(): array
    {
        return [
            'today' => ['business.today'],
            'posts' => ['business.posts.index'],
            'collections' => ['business.collections.index'],
            'brand' => ['business.brand.edit'],
            'accounts' => ['business.accounts.index'],
        ];
    }

    #[DataProvider('businessPages')]
    public function test_business_pages_never_mention_retainers_or_invoices(string $routeName): void
    {
        $organization = Organization::factory()->business()->create();
        Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $body = $this->actingAs($owner)->get(route($routeName))->getContent();

        $this->assertStringNotContainsStringIgnoringCase('retainer', $body);
        $this->assertStringNotContainsStringIgnoringCase('invoice', $body);
    }

    public function test_the_shared_workspace_prop_uses_business_role_labels(): void
    {
        $organization = Organization::factory()->business()->create();
        Client::factory()->for($organization, 'organization')->primary()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)
            ->get(route('business.today'))
            ->assertInertia(fn ($page) => $page
                ->where('workspace.type', 'business')
                ->where('workspace.roleLabels.strategist', 'Manager')
                ->where('workspace.roleLabels.designer', 'Creator'));
    }
}
