<?php

namespace Tests\Unit\Services\Push;

use App\Contracts\PushDeliveryResult;
use App\Models\PushSubscription;
use App\Services\Push\FcmPushNotifier;
use App\Support\Push\PushMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/**
 * Native shell — device tokens go to FCM, browser subscriptions fall through to the web transport,
 * and FCM's answer maps onto `Delivered` / `Gone` / `Failed` without ever throwing.
 */
class FcmPushNotifierTest extends TestCase
{
    private FakePushNotifier $web;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentialsPath, json_encode([
            'client_email' => 'push@alima-test.iam.gserviceaccount.com',
            'private_key' => 'not-a-real-key',
            'project_id' => 'alima-test',
        ]));

        config([
            'push.enabled' => true,
            'push.fcm.transport' => 'fcm',
            'push.fcm.credentials' => $this->credentialsPath,
        ]);

        $this->web = new FakePushNotifier;
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);

        parent::tearDown();
    }

    /** The real notifier with only the RSA signing stubbed (no OpenSSL key needed in tests). */
    private function notifier(): FcmPushNotifier
    {
        return new class($this->web) extends FcmPushNotifier
        {
            protected function assertion(array $credentials): string
            {
                return 'header.claims.signature';
            }
        };
    }

    private function device(): PushSubscription
    {
        return new PushSubscription(['user_id' => 1, 'platform' => 'android', 'endpoint' => 'device-token']);
    }

    private function message(): PushMessage
    {
        return new PushMessage('Time to post.', 'A story is ready.', 'https://app.test/s/abc', 'story-handoff-1');
    }

    public function test_a_device_token_is_sent_to_fcm_with_a_service_account_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/alima-test/messages/1']),
        ]);

        $result = ($this->notifier())->send($this->device(), $this->message());

        $this->assertSame(PushDeliveryResult::Delivered, $result);
        $this->assertSame([], $this->web->sent);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['assertion'] === 'header.claims.signature');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/alima-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $request['message'] == [
                'token' => 'device-token',
                'notification' => ['title' => 'Time to post.', 'body' => 'A story is ready.'],
                'data' => ['url' => 'https://app.test/s/abc', 'tag' => 'story-handoff-1'],
                'android' => ['notification' => ['tag' => 'story-handoff-1']],
                'apns' => ['headers' => ['apns-collapse-id' => 'story-handoff-1']],
            ]);
    }

    public function test_the_access_token_is_cached_between_sends(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response([]),
        ]);

        $notifier = $this->notifier();
        $notifier->send($this->device(), $this->message());
        $notifier->send($this->device(), $this->message());

        Http::assertSentCount(3);
    }

    public function test_an_unregistered_token_is_gone(): void
    {
        Cache::put('push.fcm.access-token', 'cached', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::response([
            'error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
        ], 404)]);

        $this->assertSame(PushDeliveryResult::Gone, ($this->notifier())->send($this->device(), $this->message()));
    }

    public function test_a_server_error_or_an_exception_is_failed_never_thrown(): void
    {
        Cache::put('push.fcm.access-token', 'cached', 60);
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
            ->pushStatus(401)]);

        $notifier = $this->notifier();

        $this->assertSame(PushDeliveryResult::Failed, $notifier->send($this->device(), $this->message()));
        $this->assertSame(PushDeliveryResult::Failed, $notifier->send($this->device(), $this->message()));
        // A rejected token is dropped so the next send signs a fresh one.
        $this->assertFalse(Cache::has('push.fcm.access-token'));

        Http::fake(fn () => throw new \RuntimeException('network down'));
        $this->assertSame(PushDeliveryResult::Failed, $notifier->send($this->device(), $this->message()));
    }

    public function test_the_log_transport_sends_nothing(): void
    {
        config(['push.fcm.transport' => 'log']);

        $this->assertSame(PushDeliveryResult::Failed, ($this->notifier())->send($this->device(), $this->message()));
        Http::assertNothingSent();
    }

    public function test_a_browser_subscription_goes_to_the_web_transport(): void
    {
        $browser = new PushSubscription(['user_id' => 1, 'endpoint' => 'https://push.example/x']);
        $browser->id = 7;

        $result = ($this->notifier())->send($browser, $this->message());

        $this->assertSame(PushDeliveryResult::Delivered, $result);
        $this->assertCount(1, $this->web->sent);
        Http::assertNothingSent();
    }

    public function test_it_is_configured_when_either_transport_is(): void
    {
        $this->web->configured = false;
        $this->assertTrue(($this->notifier())->isConfigured());

        config(['push.fcm.credentials' => '/nowhere/service-account.json']);
        $this->assertFalse(FcmPushNotifier::nativeConfigured());
        $this->assertFalse(($this->notifier())->isConfigured());
        $this->assertSame(PushDeliveryResult::Skipped, ($this->notifier())->send($this->device(), $this->message()));
    }
}
