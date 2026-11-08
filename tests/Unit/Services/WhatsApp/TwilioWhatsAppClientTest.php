<?php

namespace Tests\Unit\Services\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Services\WhatsApp\NullWhatsAppClient;
use App\Services\WhatsApp\TwilioWhatsAppClient;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppFailure;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Steps W-0.1, W-0.2 and W-4.1 — the Twilio client against a faked Twilio, the no-op default, and
 * per-organization credentials.
 *
 * No test here performs a real network call, and two of them exist only to prove what must *not*
 * reach a log: the auth token, and the recipient's number in full.
 */
class TwilioWhatsAppClientTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'twilio-super-secret-auth-token';

    private const SID = 'AC00000000000000000000000000000001';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.whatsapp.base_url', 'https://api.twilio.test');
        Config::set('services.whatsapp.account_sid', self::SID);
        Config::set('services.whatsapp.auth_token', self::TOKEN);
        Config::set('services.whatsapp.from', '+442079460000');
        Config::set('services.whatsapp.messaging_service_sid', null);
        Config::set('services.whatsapp.templates.post_waiting_client', 'HX0000000000000000000000000001');
        // Keep the 429 retry from actually sleeping.
        Config::set('publishing.http.retry_base_ms', 0);

        $this->organization = Organization::factory()->create();
    }

    private function client(): TwilioWhatsAppClient
    {
        return $this->app->make(TwilioWhatsAppClient::class);
    }

    private function message(): WhatsAppTemplateMessage
    {
        return MessageTemplate::PostWaitingClient->fill([
            'client_name' => 'Jess',
            'post_summary' => 'Spring menu teaser',
            'review_url' => 'https://alima.test/review/abc',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accepted(string $sid = 'SM00000000000000000000000000000009'): array
    {
        return ['sid' => $sid, 'status' => 'queued', 'error_code' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function twilioError(int $code, string $message = 'Unsupported'): array
    {
        return ['sid' => null, 'status' => 'failed', 'code' => $code, 'message' => $message];
    }

    public function test_the_null_client_is_the_off_switch_and_never_calls_anyone(): void
    {
        Config::set('services.whatsapp.client', 'null');
        Http::fake();

        $client = $this->app->make(WhatsAppClient::class);
        $this->assertInstanceOf(NullWhatsAppClient::class, $client);

        $result = $client->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertFalse($result->ok);
        $this->assertSame('not_configured', $result->errorCode);
        $this->assertFalse($client->verifyCredentials($this->organization));
        Http::assertNothingSent();
    }

    public function test_the_twilio_client_is_bound_by_default(): void
    {
        $this->assertInstanceOf(TwilioWhatsAppClient::class, $this->app->make(WhatsAppClient::class));
    }

    public function test_it_sends_a_template_and_returns_the_twilio_message_id(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->accepted())]);

        $result = $this->client()->sendTemplate($this->organization, '+44 7700 900123', $this->message());

        $this->assertTrue($result->ok);
        $this->assertSame('SM00000000000000000000000000000009', $result->messageId);
        $this->assertSame('queued', $result->providerStatus);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.twilio.test/2010-04-01/Accounts/'.self::SID.'/Messages.json'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SID.':'.self::TOKEN))
                && $body['To'] === 'whatsapp:+447700900123'
                && $body['From'] === 'whatsapp:+442079460000'
                && $body['ContentSid'] === 'HX0000000000000000000000000001'
                && $body['ContentVariables'] === '{"1":"Jess","2":"Spring menu teaser","3":"https:\/\/alima.test\/review\/abc"}';
        });
    }

    public function test_a_messaging_service_replaces_the_from_number(): void
    {
        Config::set('services.whatsapp.messaging_service_sid', 'MG00000000000000000000000000000001');
        Http::fake(['*/Messages.json' => Http::response($this->accepted())]);

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Http::assertSent(fn (Request $request) => ($request->data()['MessagingServiceSid'] ?? null) === 'MG00000000000000000000000000000001'
            && ! array_key_exists('From', $request->data()));
    }

    public function test_a_template_with_no_approved_content_sid_is_refused_before_anything_is_sent(): void
    {
        Config::set('services.whatsapp.templates.post_waiting_client', null);
        Http::fake();

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('template_not_approved', $result->errorCode);
        $this->assertSame(WhatsAppFailure::NotConfigured, $result->failure);
        Http::assertNothingSent();
    }

    public function test_session_text_sends_a_plain_body(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->accepted('SMsession'))]);

        $result = $this->client()->sendSessionText($this->organization, '+447700900123', 'On it — ten minutes.');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request) => $request->data()['Body'] === 'On it — ten minutes.'
            && ! array_key_exists('ContentSid', $request->data()));
    }

    public function test_an_unconfigured_organization_is_a_no_op_rather_than_a_failed_call(): void
    {
        Config::set('services.whatsapp.auth_token', null);
        Http::fake();

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('not_configured', $result->errorCode);
        $this->assertFalse($this->client()->verifyCredentials($this->organization));
        Http::assertNothingSent();
    }

    public function test_an_organization_with_whatsapp_switched_off_sends_nothing(): void
    {
        // W-4.1's off switch: complete credentials, deliberately disabled.
        $this->organization->forceFill([
            'whatsapp_enabled' => false,
            'whatsapp_account_sid' => 'AC00000000000000000000000000000002',
            'whatsapp_auth_token' => 'another-token',
            'whatsapp_from' => '+442079460001',
        ])->save();
        Http::fake();

        $this->assertSame('not_configured', $this->client()->sendTemplate($this->organization, '+447700900123', $this->message())->errorCode);
        Http::assertNothingSent();
    }

    public function test_an_organizations_own_credentials_win_over_the_platforms(): void
    {
        $this->organization->forceFill([
            'whatsapp_enabled' => true,
            'whatsapp_account_sid' => 'AC00000000000000000000000000000002',
            'whatsapp_auth_token' => 'the-orgs-own-token',
            'whatsapp_from' => '+442079460001',
        ])->save();
        Http::fake(['*/Messages.json' => Http::response($this->accepted())]);

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'AC00000000000000000000000000000002')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC00000000000000000000000000000002:the-orgs-own-token'))
            && $request->data()['From'] === 'whatsapp:+442079460001');
    }

    public function test_a_number_with_no_country_code_never_reaches_twilio(): void
    {
        Http::fake();

        $result = $this->client()->sendTemplate($this->organization, '07700 900123', $this->message());

        $this->assertSame('invalid_number', $result->errorCode);
        $this->assertSame(WhatsAppFailure::Permanent, $result->failure);
        Http::assertNothingSent();
    }

    public function test_an_opted_out_recipient_is_a_permanent_failure(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->twilioError(21610), 400)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('21610', $result->errorCode);
        $this->assertTrue($result->shouldSuppress());
        $this->assertStringContainsString('opted out', (string) $result->errorReason);
    }

    public function test_a_closed_24_hour_window_is_its_own_kind_of_failure(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->twilioError(63016), 400)]);

        $result = $this->client()->sendSessionText($this->organization, '+447700900123', 'hello');

        $this->assertSame(WhatsAppFailure::Window, $result->failure);
        $this->assertFalse($result->shouldSuppress());
        $this->assertStringContainsString('24-hour', (string) $result->errorReason);
    }

    public function test_a_number_with_no_whatsapp_account_is_permanent(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->twilioError(63024), 400)]);

        $this->assertTrue($this->client()->sendTemplate($this->organization, '+447700900123', $this->message())->shouldSuppress());
    }

    public function test_a_rate_limited_send_is_retried_once_and_then_succeeds(): void
    {
        Http::fake(['*/Messages.json' => Http::sequence()
            ->push($this->twilioError(20429, 'Too many requests'), 429)
            ->push($this->accepted('SMsecond')),
        ]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertTrue($result->ok);
        $this->assertSame('SMsecond', $result->messageId);
        Http::assertSentCount(2);
    }

    public function test_a_server_error_is_not_retried_because_twilio_may_already_have_sent_it(): void
    {
        Http::fake(['*/Messages.json' => Http::response(['message' => 'Oops'], 500)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertFalse($result->ok);
        $this->assertTrue($result->isRetryable());
        Http::assertSentCount(1);
    }

    public function test_verifying_credentials_reads_the_account_and_sends_nothing(): void
    {
        Http::fake(['*/Accounts/'.self::SID.'.json' => Http::response(['sid' => self::SID, 'status' => 'active'])]);

        $this->assertTrue($this->client()->verifyCredentials($this->organization));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.twilio.test/2010-04-01/Accounts/'.self::SID.'.json');
        Http::assertSentCount(1);
    }

    public function test_bad_credentials_fail_the_check_rather_than_throwing(): void
    {
        Http::fake(['*' => Http::response($this->twilioError(20003, 'Authenticate'), 401)]);

        $this->assertFalse($this->client()->verifyCredentials($this->organization));
    }

    public function test_it_never_logs_the_auth_token_or_more_than_the_last_four_digits(): void
    {
        Http::fake(['*/Messages.json' => Http::response($this->accepted())]);
        Log::spy();

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            $logged = (string) json_encode($context);

            return $message === 'WhatsApp message accepted'
                && $context['to'] === '***0123'
                && ! str_contains($logged, self::TOKEN)
                && ! str_contains($logged, '447700900123');
        });
    }

    public function test_a_refusal_logs_the_code_but_not_twilios_own_message(): void
    {
        // Twilio's text quotes the number back at us, which is why the boundary logs our reason
        // and Twilio's code instead of Twilio's message.
        Http::fake(['*/Messages.json' => Http::response(
            $this->twilioError(63024, 'Channel could not find To address whatsapp:+447700900123'), 400
        )]);
        Log::spy();

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            $logged = (string) json_encode($context);

            return $message === 'WhatsApp send refused'
                && $context['error_code'] === 63024
                && $context['to'] === '***0123'
                && ! str_contains($logged, '447700900123')
                && ! str_contains($logged, 'could not find To address')
                && ! str_contains($logged, self::TOKEN);
        });
    }
}
