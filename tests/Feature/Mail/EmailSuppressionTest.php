<?php

namespace Tests\Feature\Mail;

use App\Models\SuppressedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bounced and complained addresses stop receiving mail, and the provider webhook that reports them
 * refuses anything it cannot verify.
 *
 * The secret below is a test fixture, only ever used to sign the payloads here.
 */
class EmailSuppressionTest extends TestCase
{
    use RefreshDatabase;

    /** `whsec_` + base64("test-suppression-key"). */
    private const SECRET = 'whsec_dGVzdC1zdXBwcmVzc2lvbi1rZXk=';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.resend.webhook_secret' => self::SECRET]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload, ?string $secret = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $id = 'msg_test_1';
        $timestamp = (string) time();
        $key = (string) base64_decode(substr($secret ?? self::SECRET, strlen('whsec_')), true);
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        return $this->call('POST', route('webhooks.resend'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $timestamp,
            'HTTP_svix-signature' => "v1,{$signature}",
        ], $body);
    }

    public function test_a_bounce_suppresses_the_address(): void
    {
        $this->deliver(['type' => 'email.bounced', 'data' => ['to' => ['Gone@Example.test']]])->assertOk();

        // Normalised, so a differently-cased send is still caught.
        $this->assertTrue(SuppressedEmail::isSuppressed('gone@example.test'));
        $this->assertTrue(SuppressedEmail::isSuppressed('GONE@example.TEST'));
    }

    public function test_a_complaint_suppresses_the_address(): void
    {
        $this->deliver(['type' => 'email.complained', 'data' => ['to' => ['angry@example.test']]])->assertOk();

        $this->assertSame(
            SuppressedEmail::REASON_COMPLAINED,
            SuppressedEmail::query()->where('email', 'angry@example.test')->value('reason'),
        );
    }

    /**
     * A delayed delivery is a full mailbox or a greylisting retry — temporary. Suppressing on it
     * would quietly lose a client who was simply away.
     */
    public function test_a_delivery_delay_does_not_suppress_anything(): void
    {
        $this->deliver(['type' => 'email.delivery_delayed', 'data' => ['to' => ['slow@example.test']]])->assertOk();

        $this->assertFalse(SuppressedEmail::isSuppressed('slow@example.test'));
    }

    public function test_a_repeated_report_is_recorded_once_and_keeps_the_first_reason(): void
    {
        $payload = ['type' => 'email.bounced', 'data' => ['to' => ['gone@example.test']]];

        $this->deliver($payload)->assertOk();
        $this->deliver(['type' => 'email.complained', 'data' => ['to' => ['gone@example.test']]])->assertOk();

        $this->assertSame(1, SuppressedEmail::query()->where('email', 'gone@example.test')->count());
        $this->assertSame(
            SuppressedEmail::REASON_BOUNCED,
            SuppressedEmail::query()->where('email', 'gone@example.test')->value('reason'),
        );
    }

    // --- the endpoint is fail-closed ---------------------------------------------------------

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $this->postJson(route('webhooks.resend'), [
            'type' => 'email.bounced',
            'data' => ['to' => ['gone@example.test']],
        ])->assertStatus(401);

        $this->assertFalse(SuppressedEmail::isSuppressed('gone@example.test'));
    }

    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->deliver(
            ['type' => 'email.bounced', 'data' => ['to' => ['gone@example.test']]],
            'whsec_'.base64_encode('a-different-key'),
        )->assertStatus(401);

        $this->assertFalse(SuppressedEmail::isSuppressed('gone@example.test'));
    }

    public function test_everything_is_refused_while_no_secret_is_configured(): void
    {
        // The consequence of trusting a forged delivery is somebody else's address on a
        // platform-wide do-not-send list, so an unconfigured endpoint accepts nothing.
        config(['services.resend.webhook_secret' => null]);

        $this->deliver(['type' => 'email.bounced', 'data' => ['to' => ['gone@example.test']]])
            ->assertStatus(401);

        $this->assertFalse(SuppressedEmail::isSuppressed('gone@example.test'));
    }

    public function test_a_stale_delivery_is_refused(): void
    {
        $body = (string) json_encode(['type' => 'email.bounced', 'data' => ['to' => ['gone@example.test']]]);
        $id = 'msg_old';
        $timestamp = (string) (time() - 3600);
        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);
        $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        $this->call('POST', route('webhooks.resend'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $timestamp,
            'HTTP_svix-signature' => "v1,{$signature}",
        ], $body)->assertStatus(401);

        $this->assertFalse(SuppressedEmail::isSuppressed('gone@example.test'));
    }

    public function test_a_second_signature_from_a_rotation_is_accepted(): void
    {
        $body = (string) json_encode(['type' => 'email.bounced', 'data' => ['to' => ['gone@example.test']]]);
        $id = 'msg_rotating';
        $timestamp = (string) time();
        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);
        $good = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        // Svix sends both the old and new signature while a secret is being rotated.
        $this->call('POST', route('webhooks.resend'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $timestamp,
            'HTTP_svix-signature' => 'v1,c3RhbGU= v1,'.$good,
        ], $body)->assertOk();

        $this->assertTrue(SuppressedEmail::isSuppressed('gone@example.test'));
    }

    // --- enforcement -------------------------------------------------------------------------

    /**
     * The messages the array transport actually accepted.
     *
     * Deliberately not `Mail::fake()`: the fake replaces the mailer wholesale, so
     * `MessageSending` never fires and the very thing under test is skipped. The array transport
     * (already the test default) runs the real mailer and keeps what it was given.
     */
    private function outbox(): Collection
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();

        return $transport->messages();
    }

    public function test_a_suppressed_address_receives_nothing(): void
    {
        SuppressedEmail::record('gone@example.test', SuppressedEmail::REASON_BOUNCED);

        Mail::raw('Approval please', fn ($message) => $message->to('gone@example.test')->subject('Review'));

        // Cancelled at `MessageSending`, so it never reaches the transport at all.
        $this->assertCount(0, $this->outbox());
    }

    public function test_an_unsuppressed_address_still_receives_mail(): void
    {
        SuppressedEmail::record('gone@example.test', SuppressedEmail::REASON_BOUNCED);

        Mail::raw('Approval please', fn ($message) => $message->to('fine@example.test')->subject('Review'));

        $this->assertCount(1, $this->outbox());
    }

    /**
     * A broadcast to a mixed list still goes out — just not to the addresses that bounced. Dropping
     * the whole message would let one bad address silence a whole list.
     */
    public function test_only_the_suppressed_recipients_are_dropped_from_a_shared_message(): void
    {
        SuppressedEmail::record('gone@example.test', SuppressedEmail::REASON_BOUNCED);

        Mail::raw('Newsletter', fn ($message) => $message
            ->to('gone@example.test')
            ->cc([])
            ->subject('News'));

        $this->assertCount(0, $this->outbox());

        Mail::raw('Newsletter', function ($message) {
            $message->to('gone@example.test')->subject('News');
            $message->to('fine@example.test');
        });

        $messages = $this->outbox();
        $this->assertCount(1, $messages);

        $recipients = array_map(
            fn ($address) => $address->getAddress(),
            $messages->first()->getOriginalMessage()->getTo(),
        );

        $this->assertSame(['fine@example.test'], $recipients);
    }
}
