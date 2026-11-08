<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\WhatsAppMessageStatus;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSuppression;
use App\Support\Notifications\NotificationPreferences;
use App\Support\WhatsApp\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Steps W-3.1 and W-1.3 — Twilio's delivery callbacks and inbound STOP/START.
 *
 * The signature is the only authorisation this endpoint has, so the first thing these tests pin is
 * that an unsigned request changes nothing: without that, anyone who learned the URL could post a
 * "STOP" and silence a client's number.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'twilio-super-secret-auth-token';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.whatsapp.auth_token', self::TOKEN);
    }

    /**
     * Twilio's documented scheme, written out here independently of the implementation: the full
     * URL, then every parameter as name-then-value in name order, HMAC-SHA1 with the auth token,
     * base64.
     *
     * @param  array<string, string>  $params
     */
    private function sign(array $params, string $token = self::TOKEN): string
    {
        ksort($params, SORT_STRING);
        $data = route('webhooks.whatsapp');

        foreach ($params as $name => $value) {
            $data .= $name.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    /**
     * @param  array<string, string>  $params
     */
    private function callWebhook(array $params, ?string $signature = null)
    {
        return $this->withHeader('X-Twilio-Signature', $signature ?? $this->sign($params))
            ->post(route('webhooks.whatsapp'), $params);
    }

    private function message(array $overrides = []): WhatsAppMessage
    {
        $organization = Organization::factory()->create();

        return WhatsAppMessage::query()->create(array_merge([
            'org_id' => $organization->id,
            'to_phone' => '+447700900123',
            'template' => MessageTemplate::PostWaitingClient->value,
            'provider_message_id' => 'SM00000000000000000000000000000009',
            'status' => WhatsAppMessageStatus::Sent,
        ], $overrides));
    }

    public function test_a_signed_delivery_callback_updates_the_row(): void
    {
        $message = $this->message();

        $this->callWebhook([
            'MessageSid' => 'SM00000000000000000000000000000009',
            'MessageStatus' => 'delivered',
        ])->assertOk();

        $message->refresh();
        $this->assertSame(WhatsAppMessageStatus::Delivered, $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_an_unsigned_callback_is_refused_and_changes_nothing(): void
    {
        $message = $this->message();

        $this->callWebhook([
            'MessageSid' => 'SM00000000000000000000000000000009',
            'MessageStatus' => 'delivered',
        ], 'not-a-signature')->assertStatus(401);

        $this->assertSame(WhatsAppMessageStatus::Sent, $message->refresh()->status);
    }

    public function test_a_callback_signed_with_the_wrong_token_is_refused(): void
    {
        $this->message();

        $params = ['MessageSid' => 'SM00000000000000000000000000000009', 'MessageStatus' => 'read'];

        $this->callWebhook($params, $this->sign($params, 'some-other-token'))->assertStatus(401);
    }

    public function test_an_organizations_own_token_verifies_its_own_callbacks(): void
    {
        $message = $this->message();
        $message->organization->forceFill([
            'whatsapp_enabled' => true,
            'whatsapp_account_sid' => 'AC00000000000000000000000000000002',
            'whatsapp_auth_token' => 'the-orgs-own-token',
            'whatsapp_from' => '+442079460001',
        ])->save();

        $params = ['MessageSid' => 'SM00000000000000000000000000000009', 'MessageStatus' => 'read'];

        $this->callWebhook($params, $this->sign($params, 'the-orgs-own-token'))->assertOk();
        $this->assertSame(WhatsAppMessageStatus::Read, $message->refresh()->status);
    }

    public function test_an_out_of_order_callback_does_not_walk_the_status_backwards(): void
    {
        $message = $this->message(['status' => WhatsAppMessageStatus::Read]);

        $this->callWebhook([
            'MessageSid' => 'SM00000000000000000000000000000009',
            'MessageStatus' => 'delivered',
        ])->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Read, $message->refresh()->status);
    }

    public function test_an_unknown_message_id_is_acknowledged_rather_than_retried_forever(): void
    {
        $this->callWebhook([
            'MessageSid' => 'SMnobodyknowsthisone',
            'MessageStatus' => 'delivered',
        ])->assertOk();
    }

    public function test_an_opt_out_reported_on_a_callback_suppresses_the_number(): void
    {
        $message = $this->message();
        $user = User::factory()->whatsAppVerified()->create(['org_id' => $message->org_id]);

        $this->callWebhook([
            'MessageSid' => 'SM00000000000000000000000000000009',
            'MessageStatus' => 'failed',
            'ErrorCode' => '21610',
        ])->assertOk();

        $this->assertSame(WhatsAppMessageStatus::Failed, $message->refresh()->status);
        $this->assertSame('21610', $message->error_code);
        $this->assertTrue(WhatsAppSuppression::isSuppressed('+447700900123', $message->org_id));
        // And their own verification is cleared, so nothing tries again if a suppression is lifted.
        $this->assertNull($user->refresh()->phone_verified_at);
    }

    public function test_an_inbound_stop_suppresses_globally_and_withdraws_every_consent(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();

        $user = User::factory()->whatsAppVerified()->create(['org_id' => $organization->id]);
        $preferences = $user->notification_preferences ?? [];
        $preferences[NotificationCategory::Approvals->value]['whatsapp'] = true;
        $user->update(['notification_preferences' => $preferences]);

        $contact = ClientContact::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $client->id,
            'phone' => '+447700900123',
        ]);
        $contact->setWhatsAppConsent(true, 'staff');
        $contact->save();

        $this->callWebhook([
            'MessageSid' => 'SMinbound1',
            'From' => 'whatsapp:+447700900123',
            'To' => 'whatsapp:+442079460000',
            'Body' => 'STOP',
        ])->assertOk();

        // Global: no org_id, because deciding they only meant one agency is not ours to assume.
        $this->assertTrue(WhatsAppSuppression::isSuppressed('+447700900123', null));
        $this->assertTrue(WhatsAppSuppression::isSuppressed('+447700900123', $organization->id));

        $user->refresh();
        $this->assertNull($user->phone_verified_at);
        $this->assertFalse(NotificationPreferences::wants($user, NotificationCategory::Approvals, 'whatsapp'));

        $contact->refresh();
        $this->assertFalse($contact->receives_whatsapp);
        $this->assertNull($contact->whatsapp_consent_at);
    }

    public function test_an_inbound_start_releases_the_suppression_but_does_not_re_grant_consent(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $contact = ClientContact::factory()->create([
            'org_id' => $organization->id,
            'client_id' => $client->id,
            'phone' => '+447700900123',
        ]);

        WhatsAppSuppression::suppress('+447700900123', WhatsAppSuppression::REASON_STOP);

        $this->callWebhook([
            'MessageSid' => 'SMinbound2',
            'From' => 'whatsapp:+447700900123',
            'To' => 'whatsapp:+442079460000',
            'Body' => 'start',
        ])->assertOk();

        $this->assertFalse(WhatsAppSuppression::isSuppressed('+447700900123', $organization->id));
        // Consent is theirs to give in the portal, not a word in a chat window.
        $this->assertFalse($contact->refresh()->receives_whatsapp);
    }

    public function test_an_ordinary_inbound_message_changes_nothing(): void
    {
        WhatsAppSuppression::suppress('+447700900123', WhatsAppSuppression::REASON_STOP);

        $this->callWebhook([
            'MessageSid' => 'SMinbound3',
            'From' => 'whatsapp:+447700900123',
            'To' => 'whatsapp:+442079460000',
            'Body' => 'can you stop by tomorrow?',
        ])->assertOk();

        // "stop by" is a sentence, not an unsubscribe — and the existing suppression stands.
        $this->assertTrue(WhatsAppSuppression::isSuppressed('+447700900123', null));
        $this->assertSame(0, WhatsAppMessage::query()->withoutOrgScope()->count());
    }
}
