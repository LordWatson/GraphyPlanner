<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffInvitationAcceptControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_invitation_can_be_accepted_and_creates_a_scoped_staff_user(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $invitation = StaffInvitation::factory()->for($org, 'organization')->for($owner, 'invitedBy')->create([
            'email' => 'newhire@acme.test',
            'role' => Role::Strategist,
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $response = $this->get('/staff-invite/plain-token');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('staff-invite/show')
            ->where('email', 'newhire@acme.test')
        );

        $response = $this->post('/staff-invite/plain-token', [
            'name' => 'Ada Newhire',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertNotNull($invitation->fresh()->accepted_at);

        $user = User::where('email', 'newhire@acme.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(Role::Strategist));
        $this->assertSame($org->id, $user->org_id);
        $this->assertSame('Ada Newhire', $user->name);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_accepting_reuses_an_existing_user_for_the_same_email(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $existingUser = User::factory()->create([
            'email' => 'newhire@acme.test',
        ]);
        StaffInvitation::factory()->for($org, 'organization')->for($owner, 'invitedBy')->create([
            'email' => 'newhire@acme.test',
            'role' => Role::Designer,
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $response = $this->post('/staff-invite/plain-token', [
            'name' => 'Ada Newhire',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(1, User::where('email', 'newhire@acme.test')->count());
        $this->assertSame($existingUser->id, $existingUser->fresh()->id);
        $this->assertSame($org->id, $existingUser->fresh()->org_id);
        $this->assertTrue($existingUser->fresh()->hasRole(Role::Designer));
    }

    public function test_an_already_accepted_invitation_returns_404(): void
    {
        $invitation = StaffInvitation::factory()->create([
            'email' => 'newhire@acme.test',
            'token_hash' => hash('sha256', 'plain-token'),
            'accepted_at' => now(),
        ]);

        $this->get('/staff-invite/plain-token')->assertNotFound();
        $this->post('/staff-invite/plain-token', [
            'name' => 'Ada Newhire',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertSame(0, User::where('email', 'newhire@acme.test')->count());
    }

    public function test_an_expired_invitation_returns_404(): void
    {
        StaffInvitation::factory()->create([
            'token_hash' => hash('sha256', 'plain-token'),
            'expires_at' => now()->subDay(),
        ]);

        $this->get('/staff-invite/plain-token')->assertNotFound();
    }

    public function test_a_revoked_invitation_returns_404(): void
    {
        StaffInvitation::factory()->create([
            'token_hash' => hash('sha256', 'plain-token'),
            'revoked_at' => now(),
        ]);

        $this->get('/staff-invite/plain-token')->assertNotFound();
    }

    public function test_an_invalid_token_returns_404(): void
    {
        $this->get('/staff-invite/does-not-exist')->assertNotFound();
    }
}
