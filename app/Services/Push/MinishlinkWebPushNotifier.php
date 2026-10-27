<?php

namespace App\Services\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Step CG-1.3 — the real VAPID-backed {@see PushNotifier}, delivering through
 * `minishlink/web-push`. Bound only when `push.transport = webpush` (production, once the VAPID keys
 * are set); dev and the test suite stay on {@see WebPushNotifier} (the logger) and fake the contract
 * outright, so this class is never instantiated there.
 *
 * `send()` never throws — one dead endpoint (or a transport error) can't sink a sweep — and never
 * logs a key or the payload. A 404/410 from the push service means the browser unsubscribed, which
 * the caller prunes ({@see PushDeliveryResult::Gone}).
 */
class MinishlinkWebPushNotifier implements PushNotifier
{
    public function isConfigured(): bool
    {
        return (bool) config('push.enabled')
            && $this->nonEmpty(config('push.vapid.public_key'))
            && $this->nonEmpty(config('push.vapid.private_key'));
    }

    public function publicKey(): ?string
    {
        if (! config('push.enabled')) {
            return null;
        }

        $key = config('push.vapid.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function send(PushSubscription $subscription, PushMessage $message): PushDeliveryResult
    {
        if (! $this->isConfigured()) {
            return PushDeliveryResult::Skipped;
        }

        try {
            $report = $this->makeWebPush()->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => ContentEncoding::tryFrom((string) $subscription->content_encoding) ?? ContentEncoding::aes128gcm,
                ]),
                $message->toJson(),
            );

            if ($report->isSuccess()) {
                return PushDeliveryResult::Delivered;
            }

            // 404/410 — the subscription is dead and must be pruned by the caller.
            if ($report->isSubscriptionExpired()) {
                return PushDeliveryResult::Gone;
            }

            Log::warning('Web push delivery failed', [
                'subscription_id' => $subscription->id,
                'status' => $report->getResponse()?->getStatusCode(),
            ]);

            return PushDeliveryResult::Failed;
        } catch (Throwable $e) {
            Log::warning('Web push delivery threw', [
                'subscription_id' => $subscription->id,
                'exception' => $e::class,
            ]);

            return PushDeliveryResult::Failed;
        }
    }

    /**
     * The VAPID-authenticated transport. Extracted so a test can substitute a double without the
     * crypto extensions a real `WebPush` needs (this is never built in dev/test — the logger is
     * bound there).
     */
    protected function makeWebPush(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('push.vapid.subject'),
                'publicKey' => (string) config('push.vapid.public_key'),
                'privateKey' => (string) config('push.vapid.private_key'),
            ],
        ]);
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
