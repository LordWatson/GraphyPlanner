<?php

namespace Tests\Feature\Workspace;

use App\Actions\StaffInvitations\CreateStaffInvitationAction;
use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Step B-1.5 — a business workspace never assigns `ClientReviewer` or `SuperAdmin`; it invites
 * Owner/Manager/Creator/Viewer.
 */
class BusinessStaffRoleTest extends TestCase
{
    use RefreshDatabase;

    private function businessOwner(): User
    {
        // Creator Pro gives the 2 seats a business owner needs to invite a teammate (Step CG-2.2
        // caps Free/Pro at 1 seat).
        $organization = Organization::factory()->business()->create(['plan' => 'creator_pro']);
        (new CreatePrimaryBrandAction)($organization);

        return User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
    }

    public function test_business_org_cannot_invite_a_client_reviewer(): void
    {
        $owner = $this->businessOwner();

        $this->actingAs($owner)
            ->from(route('staff.index'))
            ->post(route('staff.invitations.store'), ['email' => 'help@bakery.test', 'role' => Role::ClientReviewer->value])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_business_org_cannot_invite_a_super_admin(): void
    {
        $owner = $this->businessOwner();

        $this->actingAs($owner)
            ->from(route('staff.index'))
            ->post(route('staff.invitations.store'), ['email' => 'help@bakery.test', 'role' => Role::SuperAdmin->value])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_business_org_can_invite_a_creator(): void
    {
        Mail::fake();
        $owner = $this->businessOwner();

        $this->actingAs($owner)
            ->post(route('staff.invitations.store'), ['email' => 'editor@bakery.test', 'role' => Role::Designer->value])
            ->assertRedirect(route('staff.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('staff_invitations', [
            'org_id' => $owner->org_id,
            'email' => 'editor@bakery.test',
            'role' => Role::Designer->value,
        ]);
    }

    public function test_the_action_rejects_an_unassignable_role_for_a_business_org(): void
    {
        $organization = Organization::factory()->business()->create();
        $inviter = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();

        $this->expectException(InvalidArgumentException::class);

        (new CreateStaffInvitationAction)($organization, 'nope@bakery.test', Role::SuperAdmin, $inviter);

        $this->assertDatabaseCount('staff_invitations', 0);
    }
}
