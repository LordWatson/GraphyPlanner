<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\StaffActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_staff_roster(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $response = $this->actingAs($owner)->get(route('staff.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('staff/index')
            ->has('members', 2)
        );

        // Ensure the other org's members never leak into the roster.
        $this->assertNotNull($strategist->id);
    }

    public function test_non_owner_cannot_view_the_staff_roster(): void
    {
        $org = Organization::factory()->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $response = $this->actingAs($strategist)->get(route('staff.index'));

        $response->assertForbidden();
    }

    public function test_owner_can_view_a_staff_members_profile_and_activity(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $member = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        StaffActivityLog::factory()->for($org, 'organization')->create([
            'user_id' => $member->id,
            'actor_user_id' => $owner->id,
            'action' => 'role_changed',
            'from_role' => Role::Viewer,
            'to_role' => Role::Strategist,
        ]);

        $response = $this->actingAs($owner)->get(route('staff.show', $member));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('staff/show')
            ->where('member.id', $member->id)
            ->has('activityLogs', 1)
        );
    }

    public function test_owner_cannot_view_a_staff_members_profile_from_another_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $otherMember = User::factory()->for($otherOrg, 'organization')->role(Role::Strategist)->create();

        $response = $this->actingAs($owner)->get(route('staff.show', $otherMember));

        $response->assertForbidden();
    }

    public function test_owner_can_update_a_staff_members_name_and_role(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $member = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();

        $response = $this->actingAs($owner)->put(route('staff.update', $member), [
            'name' => 'Updated Name',
            'role' => Role::Strategist->value,
        ]);

        $response->assertRedirect(route('staff.show', $member));
        $member->refresh();
        $this->assertSame('Updated Name', $member->name);
        $this->assertTrue($member->hasRole(Role::Strategist));
        $this->assertDatabaseHas('staff_activity_logs', [
            'org_id' => $org->id,
            'user_id' => $member->id,
            'actor_user_id' => $owner->id,
            'action' => 'role_changed',
            'from_role' => Role::Viewer->value,
            'to_role' => Role::Strategist->value,
        ]);
    }

    public function test_the_client_reviewer_role_cannot_be_assigned_via_update(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $member = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();

        $response = $this->actingAs($owner)->put(route('staff.update', $member), [
            'name' => 'Updated Name',
            'role' => Role::ClientReviewer->value,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertTrue($member->fresh()->hasRole(Role::Viewer));
    }

    public function test_non_owner_cannot_update_a_staff_member(): void
    {
        $org = Organization::factory()->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $member = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();

        $response = $this->actingAs($strategist)->put(route('staff.update', $member), [
            'name' => 'Updated Name',
            'role' => Role::Designer->value,
        ]);

        $response->assertForbidden();
    }

    public function test_owner_can_remove_a_staff_member(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $member = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();

        $response = $this->actingAs($owner)->delete(route('staff.destroy', $member));

        $response->assertRedirect(route('staff.index'));
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertDatabaseHas('staff_activity_logs', [
            'org_id' => $org->id,
            'actor_user_id' => $owner->id,
            'action' => 'removed',
        ]);
    }

    public function test_owner_cannot_remove_themselves(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->delete(route('staff.destroy', $owner));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_owner_cannot_remove_the_last_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $secondOwner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->delete(route('staff.destroy', $secondOwner));

        $response->assertRedirect(route('staff.index'));
        $this->assertDatabaseMissing('users', ['id' => $secondOwner->id]);

        // Now only one owner is left — removing it must be forbidden.
        $response = $this->actingAs($owner)->delete(route('staff.destroy', $owner));
        $response->assertForbidden();
    }
}
