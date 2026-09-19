<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\StaffInvitationMail;
use App\Models\Organization;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_staff_invitation(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->post(route('staff.invitations.store'), [
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist->value,
        ]);

        $response->assertRedirect(route('staff.index'));
        $this->assertDatabaseHas('staff_invitations', [
            'org_id' => $org->id,
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist->value,
            'invited_by_user_id' => $owner->id,
        ]);
        Mail::assertSent(StaffInvitationMail::class);
    }

    public function test_a_duplicate_pending_invitation_to_the_same_email_is_rejected(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        StaffInvitation::factory()->for($org, 'organization')->for($owner, 'invitedBy')->create([
            'email' => 'newhire@acme.test',
        ]);

        $response = $this->actingAs($owner)->post(route('staff.invitations.store'), [
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist->value,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('staff_invitations', 1);
    }

    public function test_the_client_reviewer_role_cannot_be_assigned(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($owner)->post(route('staff.invitations.store'), [
            'email' => 'newhire@acme.test',
            'role' => Role::ClientReviewer->value,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_a_non_owner_cannot_send_a_staff_invitation(): void
    {
        $org = Organization::factory()->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $response = $this->actingAs($strategist)->post(route('staff.invitations.store'), [
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('staff_invitations', 0);
    }

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $invitation = StaffInvitation::factory()->for($org, 'organization')->for($owner, 'invitedBy')->create();

        $response = $this->actingAs($owner)->delete(route('staff.invitations.destroy', $invitation));

        $response->assertRedirect(route('staff.index'));
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_user_from_another_org_cannot_revoke_an_invitation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $invitation = StaffInvitation::factory()->for($org, 'organization')->for($owner, 'invitedBy')->create();

        $response = $this->actingAs($otherOwner)->delete(route('staff.invitations.destroy', $invitation));

        $response->assertForbidden();
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_a_non_owner_cannot_view_the_staff_settings_page(): void
    {
        $org = Organization::factory()->create();
        $viewer = User::factory()->for($org, 'organization')->role(Role::Viewer)->create();

        $response = $this->actingAs($viewer)->get(route('staff.index'));

        $response->assertForbidden();
    }

    public function test_the_invitation_token_never_appears_in_the_rendered_staff_page(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->post(route('staff.invitations.store'), [
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist->value,
        ]);

        $tokenHash = StaffInvitation::first()->token_hash;

        $response = $this->actingAs($owner)->get(route('staff.index'));

        $response->assertOk();
        $response->assertDontSee($tokenHash, false);
        $response->assertInertia(fn ($page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.email', 'newhire@acme.test')
        );
    }
}
