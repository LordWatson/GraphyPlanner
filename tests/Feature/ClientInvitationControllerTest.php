<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ClientInvitationMail;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_client_invitation(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('client_invitations', [
            'client_id' => $client->id,
            'email' => 'contact@acme.test',
            'invited_by_user_id' => $owner->id,
        ]);
        Mail::assertSent(ClientInvitationMail::class);
    }

    public function test_a_duplicate_pending_invitation_to_the_same_email_is_rejected(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create([
            'email' => 'contact@acme.test',
        ]);

        $response = $this->actingAs($owner)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('client_invitations', 1);
    }

    public function test_a_new_invitation_can_be_sent_once_the_prior_one_is_revoked(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create([
            'email' => 'contact@acme.test',
            'revoked_at' => now(),
        ]);

        $response = $this->actingAs($owner)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseCount('client_invitations', 2);
    }

    public function test_designer_cannot_send_a_client_invitation(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('client_invitations', 0);
    }

    public function test_user_from_another_org_cannot_send_a_client_invitation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('client_invitations', 0);
    }

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $invitation = ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create();

        $response = $this->actingAs($owner)->delete(route('invitations.destroy', $invitation));

        $response->assertRedirect(route('clients.show', $client));
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_user_from_another_org_cannot_revoke_an_invitation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();
        $invitation = ClientInvitation::factory()->for($client)->for($owner, 'invitedBy')->create();

        $response = $this->actingAs($otherOwner)->delete(route('invitations.destroy', $invitation));

        $response->assertForbidden();
        $this->assertNull($invitation->fresh()->revoked_at);
    }

    public function test_the_invitation_token_never_appears_in_the_rendered_clients_show_page(): void
    {
        Mail::fake();

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)->post(route('clients.invitations.store', $client), [
            'email' => 'contact@acme.test',
        ]);

        $tokenHash = ClientInvitation::first()->token_hash;

        $response = $this->actingAs($owner)->get(route('clients.show', $client));

        $response->assertOk();
        $response->assertDontSee($tokenHash, false);
        $response->assertInertia(fn ($page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.email', 'contact@acme.test')
        );
    }
}
