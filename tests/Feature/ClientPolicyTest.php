<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role, ?Organization $org = null): User
    {
        $org ??= Organization::factory()->create();

        return User::factory()->for($org, 'organization')->role($role)->create();
    }

    public function test_owner_can_do_everything_on_clients(): void
    {
        $owner = $this->makeUser(Role::Owner);
        $client = Client::factory()->create(['org_id' => $owner->org_id]);

        $this->assertTrue($owner->can('viewAny', Client::class));
        $this->assertTrue($owner->can('view', $client));
        $this->assertTrue($owner->can('create', Client::class));
        $this->assertTrue($owner->can('update', $client));
        $this->assertTrue($owner->can('delete', $client));
        $this->assertTrue($owner->can('viewBilling', $client));
    }

    public function test_strategist_can_manage_but_not_delete_clients(): void
    {
        $strategist = $this->makeUser(Role::Strategist);
        $client = Client::factory()->create(['org_id' => $strategist->org_id]);

        $this->assertTrue($strategist->can('viewAny', Client::class));
        $this->assertTrue($strategist->can('view', $client));
        $this->assertTrue($strategist->can('create', Client::class));
        $this->assertTrue($strategist->can('update', $client));
        $this->assertFalse($strategist->can('delete', $client));
        $this->assertTrue($strategist->can('viewBilling', $client));
    }

    public function test_designer_is_restricted_to_read_only_and_no_billing(): void
    {
        $designer = $this->makeUser(Role::Designer);
        $client = Client::factory()->create(['org_id' => $designer->org_id]);

        $this->assertTrue($designer->can('viewAny', Client::class));
        $this->assertTrue($designer->can('view', $client));
        $this->assertFalse($designer->can('create', Client::class));
        $this->assertFalse($designer->can('update', $client));
        $this->assertFalse($designer->can('delete', $client));
        $this->assertFalse($designer->can('viewBilling', $client));
    }

    public function test_viewer_has_read_only_access_and_no_billing(): void
    {
        $viewer = $this->makeUser(Role::Viewer);
        $client = Client::factory()->create(['org_id' => $viewer->org_id]);

        $this->assertTrue($viewer->can('viewAny', Client::class));
        $this->assertTrue($viewer->can('view', $client));
        $this->assertFalse($viewer->can('create', Client::class));
        $this->assertFalse($viewer->can('update', $client));
        $this->assertFalse($viewer->can('delete', $client));
        $this->assertFalse($viewer->can('viewBilling', $client));
    }

    public function test_client_reviewer_can_only_view_their_own_scoped_client_with_no_billing(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->create(['org_id' => $org->id]);
        $otherClient = Client::factory()->create(['org_id' => $org->id]);

        $reviewer = $this->makeUser(Role::ClientReviewer, $org);
        $reviewer->forceFill(['client_id' => $client->id])->save();

        $this->assertFalse($reviewer->can('viewAny', Client::class));
        $this->assertTrue($reviewer->can('view', $client));
        $this->assertFalse($reviewer->can('view', $otherClient));
        $this->assertFalse($reviewer->can('create', Client::class));
        $this->assertFalse($reviewer->can('update', $client));
        $this->assertFalse($reviewer->can('delete', $client));
        $this->assertFalse($reviewer->can('viewBilling', $client));
    }
}
