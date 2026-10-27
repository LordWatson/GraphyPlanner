<?php

namespace Tests\Unit\Services\Push;

use App\Contracts\PushDeliveryResult;
use App\Models\PushSubscription;
use App\Services\Push\MinishlinkWebPushNotifier;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\TestCase;

/**
 * Step CG-1.3 — the real VAPID transport's status → {@see PushDeliveryResult} mapping, with the
 * `WebPush` client doubled (no crypto extensions needed; this transport is never built in dev/test).
 */
class MinishlinkWebPushNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'push.enabled' => true,
            'push.vapid.public_key' => 'test-public-key',
            'push.vapid.private_key' => 'test-private-key',
            'push.vapid.subject' => 'mailto:hello@alima.app',
        ]);
    }

    private function subscription(): PushSubscription
    {
        return PushSubscription::make([
            'endpoint' => 'https://push.example/abc',
            'public_key' => 'p256dh',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function notifierReturning(MessageSentReport $report): MinishlinkWebPushNotifier
    {
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('sendOneNotification')->once()->andReturn($report);

        return new class($webPush) extends MinishlinkWebPushNotifier
        {
            public function __construct(private readonly WebPush $webPush) {}

            protected function makeWebPush(): WebPush
            {
                return $this->webPush;
            }
        };
    }

    public function test_a_successful_send_is_delivered(): void
    {
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturnTrue();

        $result = $this->notifierReturning($report)->send($this->subscription(), new PushMessage('Title', 'Body'));

        $this->assertSame(PushDeliveryResult::Delivered, $result);
    }

    public function test_an_expired_subscription_is_gone(): void
    {
        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturnFalse();
        $report->shouldReceive('isSubscriptionExpired')->andReturnTrue();

        $result = $this->notifierReturning($report)->send($this->subscription(), new PushMessage('Title', 'Body'));

        $this->assertSame(PushDeliveryResult::Gone, $result);
    }

    public function test_another_failure_is_failed_and_never_logs_a_key(): void
    {
        Log::spy();

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturnFalse();
        $report->shouldReceive('isSubscriptionExpired')->andReturnFalse();
        $report->shouldReceive('getResponse')->andReturnNull();

        $result = $this->notifierReturning($report)->send($this->subscription(), new PushMessage('Title', 'Body'));

        $this->assertSame(PushDeliveryResult::Failed, $result);

        // The VAPID private key must never appear in a log line.
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return ! str_contains(json_encode([$message, $context]) ?: '', 'test-private-key');
        });
    }

    public function test_it_skips_when_not_configured(): void
    {
        config(['push.enabled' => false]);

        // makeWebPush() is never reached, so the plain notifier (no double) is fine here.
        $result = (new MinishlinkWebPushNotifier)->send($this->subscription(), new PushMessage('Title', 'Body'));

        $this->assertSame(PushDeliveryResult::Skipped, $result);
    }
}
