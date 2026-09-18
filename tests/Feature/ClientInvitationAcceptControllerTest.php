<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientInvitationAcceptControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_invitation_can_be_accepted_and_creates_a_scoped_client_reviewer_user(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invitation = ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create([
            'email' => 'contact@acme.test',
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $response = $this->get('/client-invite/plain-token');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('client-invite/show')
            ->where('email', 'contact@acme.test')
        );

        $response = $this->post('/client-invite/plain-token', [
            'name' => 'Ada Contact',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('login'));

        $this->assertNotNull($invitation->fresh()->accepted_at);

        $user = User::where('email', 'contact@acme.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(Role::ClientReviewer));
        $this->assertSame($client->id, $user->client_id);
        $this->assertSame($org->id, $user->org_id);
        $this->assertSame('Ada Contact', $user->name);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password));
    }

    public function test_accepting_reuses_an_existing_client_reviewer_user_for_the_same_email(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $existingUser = User::factory()->role(Role::ClientReviewer)->create([
            'email' => 'contact@acme.test',
        ]);
        $invitation = ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create([
            'email' => 'contact@acme.test',
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $response = $this->post('/client-invite/plain-token', [
            'name' => 'Ada Contact',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(1, User::where('email', 'contact@acme.test')->count());
        $this->assertSame($existingUser->id, $existingUser->fresh()->id);
        $this->assertSame($client->id, $existingUser->fresh()->client_id);
        $this->assertSame($org->id, $existingUser->fresh()->org_id);
    }

    public function test_an_already_accepted_invitation_returns_404(): void
    {
        $invitation = ClientInvitation::factory()->create([
            'email' => 'contact@acme.test',
            'token_hash' => hash('sha256', 'plain-token'),
            'accepted_at' => now(),
        ]);

        $this->get('/client-invite/plain-token')->assertNotFound();
        $this->post('/client-invite/plain-token', [
            'name' => 'Ada Contact',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertSame(0, User::where('email', 'contact@acme.test')->count());
    }

    public function test_an_expired_invitation_returns_404(): void
    {
        ClientInvitation::factory()->create([
            'token_hash' => hash('sha256', 'plain-token'),
            'expires_at' => now()->subDay(),
        ]);

        $this->get('/client-invite/plain-token')->assertNotFound();
    }

    public function test_a_revoked_invitation_returns_404(): void
    {
        ClientInvitation::factory()->create([
            'token_hash' => hash('sha256', 'plain-token'),
            'revoked_at' => now(),
        ]);

        $this->get('/client-invite/plain-token')->assertNotFound();
    }

    public function test_an_invalid_token_returns_404(): void
    {
        $this->get('/client-invite/does-not-exist')->assertNotFound();
    }

    public function test_the_new_client_reviewer_user_has_no_cross_client_access(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherClient = Client::factory()->for($org, 'organization')->create();
        ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create([
            'email' => 'contact@acme.test',
            'token_hash' => hash('sha256', 'plain-token'),
        ]);

        $this->post('/client-invite/plain-token', [
            'name' => 'Ada Contact',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'contact@acme.test')->first();

        $this->assertSame($client->id, $user->client_id);
        $this->assertNotSame($otherClient->id, $user->client_id);
    }
}
