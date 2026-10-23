<?php

namespace Tests\Support;

use App\Contracts\PushDeliveryResult;
use App\Contracts\PushNotifier;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;

/**
 * Step B-7.4 — a test double for the push transport: it records every send and reports whatever
 * delivery result the test sets up, so subscription storage, opt-out, reminder dispatch and
 * dead-subscription pruning can all be asserted without a real VAPID push service.
 *
 * @phpstan-type Sent array{subscription_id: int, message: PushMessage}
 */
class FakePushNotifier implements PushNotifier
{
    /** @var list<array{subscription_id: int, message: PushMessage}> */
    public array $sent = [];

    public bool $configured = true;

    /** @var list<string> endpoints that should report themselves gone (to be pruned) */
    public array $goneEndpoints = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function publicKey(): ?string
    {
        return $this->configured ? 'BFakePublicKeyForTests' : null;
    }

    public function send(PushSubscription $subscription, PushMessage $message): PushDeliveryResult
    {
        $this->sent[] = ['subscription_id' => $subscription->id, 'message' => $message];

        return in_array($subscription->endpoint, $this->goneEndpoints, true)
            ? PushDeliveryResult::Gone
            : PushDeliveryResult::Delivered;
    }
}
