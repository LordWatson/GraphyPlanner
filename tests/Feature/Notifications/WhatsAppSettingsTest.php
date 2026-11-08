<?php

namespace Tests\Feature\Notifications;

use App\Contracts\WhatsAppClient;
use App\Enums\ClientContactRole;
use App\Enums\Role;
use App\Enums\WhatsAppMessageStatus;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppCredentials;
use App\Support\WhatsApp\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Steps W-1.2, W-4.1 and W-4.2 — client-contact consent, the Owner's credentials, and the log.
 *
 * Two of these tests exist for one reason: the auth token must not reach the browser, and the
 * recipient's full number must not either.
 */
class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(?Organization $organization = null): User
    {
        return User::factory()->role(Role::Owner)->create([
            'org_id' => ($organization ?? Organization::factory()->create())->id,
        ]);
    }

    private function contactPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jess Baker',
            'email' => 'jess@example.test',
            'phone' => '+44 7700 900123',
            'role' => ClientContactRole::Approver->value,
            'receives_review_emails' => '1',
            'receives_invoices' => '0',
            'receives_whatsapp' => '1',
        ], $overrides);
    }

    public function test_staff_can_record_a_contacts_consent_with_its_evidence(): void
    {
        $owner = $this->owner();
        $client = Client::factory()->create(['org_id' => $owner->org_id]);

        $this->actingAs($owner)
            ->post(route('clients.contacts.store', $client), $this->contactPayload())
            ->assertRedirect();

        $contact = ClientContact::query()->withoutOrgScope()->firstOrFail();

        $this->assertTrue($contact->receives_whatsapp);
        $this->assertNotNull($contact->whatsapp_consent_at);
        $this->assertSame('staff', $contact->whatsapp_consent_source);
        $this->assertSame($owner->id, $contact->whatsapp_consent_by);
        $this->assertSame('+447700900123', $contact->routeNotificationForWhatsApp());
    }

    public function test_consent_without_a_dialable_number_is_refused(): void
    {
        $owner = $this->owner();
        $client = Client::factory()->create(['org_id' => $owner->org_id]);

        $this->actingAs($owner)
            ->post(route('clients.contacts.store', $client), $this->contactPayload(['phone' => '07700 900123']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, ClientContact::query()->withoutOrgScope()->count());
    }

    public function test_a_designer_cannot_record_consent(): void
    {
        $organization = Organization::factory()->create();
        $designer = User::factory()->role(Role::Designer)->create(['org_id' => $organization->id]);
        $client = Client::factory()->create(['org_id' => $organization->id]);

        $this->actingAs($designer)
            ->post(route('clients.contacts.store', $client), $this->contactPayload())
            ->assertForbidden();
    }

    public function test_an_owner_can_store_whatsapp_credentials_and_the_token_never_comes_back(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('organization.update'), [
                'default_timezone' => 'Europe/London',
                'default_currency' => 'gbp',
                'whatsapp_enabled' => '1',
                'whatsapp_account_sid' => 'AC00000000000000000000000000000001',
                'whatsapp_auth_token' => 'the-secret-twilio-token',
                'whatsapp_from' => '+442079460000',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $organization = $owner->organization->refresh();

        $this->assertTrue($organization->whatsapp_enabled);
        // Encrypted at rest, like every other credential on this table.
        $this->assertSame('the-secret-twilio-token', $organization->whatsapp_auth_token);
        $this->assertNotSame('the-secret-twilio-token', $organization->getRawOriginal('whatsapp_auth_token'));

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('organization.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('organization.whatsapp_enabled', true)
                ->where('organization.has_whatsapp_auth_token', true)
                ->where('organization.whatsapp_auth_token_last4', 'oken')
                ->where('organization.whatsapp_configured', true)
                ->missing('organization.whatsapp_auth_token'))
            ->assertDontSee('the-secret-twilio-token');
    }

    public function test_a_blank_token_keeps_the_stored_one(): void
    {
        $owner = $this->owner();
        $owner->organization->forceFill(['whatsapp_auth_token' => 'keep-me'])->save();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->put(route('organization.update'), [
            'default_timezone' => 'Europe/London',
            'default_currency' => 'gbp',
            'whatsapp_auth_token' => '',
        ])->assertRedirect();

        $this->assertSame('keep-me', $owner->organization->refresh()->whatsapp_auth_token);
    }

    public function test_removing_the_credentials_switches_whatsapp_off(): void
    {
        $owner = $this->owner();
        $owner->organization->forceFill([
            'whatsapp_enabled' => true,
            'whatsapp_account_sid' => 'AC1',
            'whatsapp_auth_token' => 'token',
            'whatsapp_from' => '+442079460000',
        ])->save();

        $this->actingAs($owner)->delete(route('organization.whatsapp.destroy'))->assertRedirect();

        $organization = $owner->organization->refresh();
        $this->assertFalse($organization->whatsapp_enabled);
        $this->assertNull($organization->whatsapp_auth_token);
        $this->assertFalse(app(WhatsAppCredentials::class)->isConfiguredFor($organization));
    }

    public function test_a_non_owner_cannot_reach_the_credentials(): void
    {
        $organization = Organization::factory()->create();
        $strategist = User::factory()->role(Role::Strategist)->create(['org_id' => $organization->id]);

        $this->actingAs($strategist)->delete(route('organization.whatsapp.destroy'))->assertForbidden();
    }

    public function test_test_connection_reports_what_twilio_said(): void
    {
        $owner = $this->owner();
        $this->app->instance(WhatsAppClient::class, new RecordingWhatsAppClient);

        $this->actingAs($owner)
            ->post(route('organization.test-whatsapp'))
            ->assertRedirect(route('organization.edit'));
    }

    public function test_the_log_lists_this_organizations_messages_with_masked_numbers(): void
    {
        $owner = $this->owner();
        WhatsAppMessage::query()->create([
            'org_id' => $owner->org_id,
            'user_id' => $owner->id,
            'to_phone' => '+447700900123',
            'template' => MessageTemplate::PostWaitingClient->value,
            'notification_type' => 'App\\Notifications\\PostWaitingForClientReview',
            'status' => WhatsAppMessageStatus::Delivered,
        ]);

        $this->actingAs($owner)
            ->get(route('whatsapp-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.masked_phone', '***0123')
                ->where('messages.data.0.status_label', 'Delivered'))
            ->assertDontSee('447700900123');
    }

    public function test_the_log_never_shows_another_organizations_messages(): void
    {
        $other = Organization::factory()->create();
        WhatsAppMessage::query()->create([
            'org_id' => $other->id,
            'to_phone' => '+447700900999',
            'template' => MessageTemplate::Notification->value,
            'status' => WhatsAppMessageStatus::Sent,
        ]);

        $this->actingAs($this->owner())
            ->get(route('whatsapp-log.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('messages.data', 0));
    }

    public function test_a_designer_cannot_read_the_log(): void
    {
        $organization = Organization::factory()->create();
        $designer = User::factory()->role(Role::Designer)->create(['org_id' => $organization->id]);

        $this->actingAs($designer)->get(route('whatsapp-log.index'))->assertForbidden();
    }
}
