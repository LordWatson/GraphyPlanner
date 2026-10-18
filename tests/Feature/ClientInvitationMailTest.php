<?php

namespace Tests\Feature;

use App\Actions\ClientInvitations\CreateClientInvitationAction;
use App\Enums\Role;
use App\Mail\ClientInvitationMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientInvitationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invitation_mail_renders_without_lazy_loading_the_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $invitation = app(CreateClientInvitationAction::class)($client, 'contact@acme.test', $owner);

        $mailable = new ClientInvitationMail($invitation, 'https://example.test/client-invite/token');

        $rendered = $mailable->render();

        $this->assertStringContainsString($client->name, $rendered);
    }
}
