<?php

namespace App\Services\Push;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Log;

/**
 * Step B-7.4 — the default {@see PushNotifier}. Push is "configured" when it's enabled and a VAPID
 * key pair is present ({@see config/push.php}), which is all the browser needs to subscribe, so the
 * whole opt-in → store → reminder pipeline is exercised end-to-end.
 *
 * The one piece that needs a vendor is the final encrypted delivery (a VAPID transport such as
 * `minishlink/web-push`). That's deliberately **not** bundled: this default logs the intent and
 * reports `Failed` so the feature ships dark without a dependency. Enabling real delivery is one
 * line — bind {@see PushNotifier} to a transport-backed implementation in a service provider; no
 * caller changes. Tests fake the contract outright.
 */
class WebPushNotifier implements PushNotifier
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

        // Configured but no delivery transport is bound — surface it once per attempt so an operator
        // notices rather than reminders vanishing silently. Never leaks the payload or any key.
        Log::warning('Push configured but no delivery transport is bound — bind a VAPID-backed PushNotifier to deliver.', [
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'tag' => $message->tag,
        ]);

        return PushDeliveryResult::Failed;
    }

    private function nonEmpty(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
