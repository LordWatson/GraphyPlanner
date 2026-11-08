<?php

namespace Tests\Unit\Services\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Models\Organization;
use App\Services\WhatsApp\CloudApiWhatsAppClient;
use App\Services\WhatsApp\NullWhatsAppClient;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Steps W-0.1 and W-0.2 — the WhatsApp seam: the no-op default, and the Cloud API client against a
 * faked Meta.
 *
 * No test here performs a real network call, and two of them exist only to prove what must *not*
 * be in a log: the access token, and the recipient's number in full.
 */
class WhatsAppClientTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAG-super-secret-system-user-token';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.whatsapp.base_url', 'https://graph.facebook.test');
        Config::set('services.whatsapp.graph_version', 'v21.0');
        Config::set('services.whatsapp.phone_number_id', '555000111');
        Config::set('services.whatsapp.access_token', self::TOKEN);
        // Keep the 429 retry from actually sleeping.
        Config::set('publishing.http.retry_base_ms', 0);

        $this->organization = Organization::factory()->create();
    }

    private function client(): CloudApiWhatsAppClient
    {
        return $this->app->make(CloudApiWhatsAppClient::class);
    }

    private function message(): WhatsAppTemplateMessage
    {
        return new WhatsAppTemplateMessage('post_awaiting_approval', ['Jess', "Spring   menu\nteaser"]);
    }

    private function accepted(string $id = 'wamid.HBgLNDQ3NzAwOTAwMTIz'): array
    {
        return ['messaging_product' => 'whatsapp', 'messages' => [['id' => $id]]];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function metaError(int $code, array $extra = []): array
    {
        return ['error' => array_merge(['message' => 'Unsupported', 'type' => 'OAuthException', 'code' => $code], $extra)];
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

    public function test_the_cloud_api_client_is_bound_by_default(): void
    {
        $this->assertInstanceOf(CloudApiWhatsAppClient::class, $this->app->make(WhatsAppClient::class));
    }

    public function test_an_unconfigured_organization_is_a_no_op_rather_than_a_failed_call(): void
    {
        Config::set('services.whatsapp.access_token', null);
        Http::fake();

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('not_configured', $result->errorCode);
        $this->assertFalse($this->client()->verifyCredentials($this->organization));
        Http::assertNothingSent();
    }

    public function test_it_sends_a_template_and_returns_metas_message_id(): void
    {
        Http::fake(['*/messages' => Http::response($this->accepted())]);

        $result = $this->client()->sendTemplate($this->organization, '+44 7700 900123', $this->message());

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.HBgLNDQ3NzAwOTAwMTIz', $result->messageId);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.test/v21.0/555000111/messages'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $body['messaging_product'] === 'whatsapp'
                && $body['to'] === '+447700900123'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'post_awaiting_approval'
                && $body['template']['language'] === ['code' => 'en_GB']
                // Positional, and whitespace-collapsed: Meta refuses a variable with a newline.
                && $body['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => 'Jess'],
                    ['type' => 'text', 'text' => 'Spring menu teaser'],
                ];
        });
    }

    public function test_session_text_sends_plain_text_with_link_previews_off(): void
    {
        Http::fake(['*/messages' => Http::response($this->accepted('wamid.session'))]);

        $result = $this->client()->sendSessionText($this->organization, '+447700900123', 'On it — give me ten minutes.');

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && $request->data()['text'] === ['preview_url' => false, 'body' => 'On it — give me ten minutes.']);
    }

    public function test_a_number_with_no_country_code_never_reaches_meta(): void
    {
        Http::fake();

        $result = $this->client()->sendTemplate($this->organization, '07700 900123', $this->message());

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_number', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_a_closed_24_hour_window_comes_back_as_a_named_reason(): void
    {
        Http::fake(['*/messages' => Http::response($this->metaError(131047), 400)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertFalse($result->ok);
        $this->assertSame('131047', $result->errorCode);
        $this->assertStringContainsString('24-hour', (string) $result->errorReason);
    }

    public function test_an_undeliverable_number_comes_back_as_a_named_reason(): void
    {
        Http::fake(['*/messages' => Http::response($this->metaError(131026), 400)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('131026', $result->errorCode);
        $this->assertStringContainsString('cannot deliver', (string) $result->errorReason);
    }

    public function test_a_template_error_is_reported_as_a_template_error(): void
    {
        Http::fake(['*/messages' => Http::response($this->metaError(132001), 400)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertSame('132001', $result->errorCode);
        $this->assertStringContainsString('template was refused', (string) $result->errorReason);
    }

    public function test_a_rate_limited_send_is_retried_once_and_then_succeeds(): void
    {
        Http::fake(['*/messages' => Http::sequence()
            ->push(['error' => ['code' => 130429, 'message' => 'Rate limit hit']], 429)
            ->push($this->accepted('wamid.second-try')),
        ]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.second-try', $result->messageId);
        Http::assertSentCount(2);
    }

    public function test_a_server_error_is_not_retried_because_meta_may_already_have_delivered_it(): void
    {
        Http::fake(['*/messages' => Http::response(['error' => ['code' => 1, 'message' => 'Oops']], 500)]);

        $result = $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        $this->assertFalse($result->ok);
        Http::assertSentCount(1);
    }

    public function test_verifying_credentials_reads_the_number_and_sends_nothing(): void
    {
        Http::fake(['*/v21.0/555000111' => Http::response(['id' => '555000111', 'display_phone_number' => '+44 20 7946 0000'])]);

        $this->assertTrue($this->client()->verifyCredentials($this->organization));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.test/v21.0/555000111');
        Http::assertSentCount(1);
    }

    public function test_bad_credentials_fail_the_check_rather_than_throwing(): void
    {
        Http::fake(['*' => Http::response($this->metaError(190), 401)]);

        $this->assertFalse($this->client()->verifyCredentials($this->organization));
    }

    public function test_it_never_logs_the_access_token_or_more_than_the_last_four_digits(): void
    {
        Http::fake(['*/messages' => Http::response($this->accepted())]);
        Log::spy();

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            $logged = (string) json_encode($context);

            return $message === 'WhatsApp message accepted'
                && $context['to'] === '***0123'
                && $context['wa_message_id'] === 'wamid.HBgLNDQ3NzAwOTAwMTIz'
                && ! str_contains($logged, self::TOKEN)
                && ! str_contains($logged, '447700900123');
        });
    }

    public function test_a_refusal_logs_the_code_but_not_metas_own_message(): void
    {
        // 131030's message quotes the recipient's full number back at us, which is exactly why the
        // boundary logs our reason and Meta's code instead of Meta's text.
        Http::fake(['*/messages' => Http::response($this->metaError(131030, [
            'error_subcode' => 2655007,
            'error_data' => ['details' => 'Recipient phone number not in allowed list: Add +447700900123'],
        ]), 400)]);
        Log::spy();

        $this->client()->sendTemplate($this->organization, '+447700900123', $this->message());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            $logged = (string) json_encode($context);

            return $message === 'WhatsApp send refused'
                && $context['error_code'] === 131030
                && $context['error_subcode'] === 2655007
                && $context['to'] === '***0123'
                && ! str_contains($logged, '447700900123')
                && ! str_contains($logged, 'not in allowed list')
                && ! str_contains($logged, self::TOKEN);
        });
    }
}
