<?php

namespace App\Contracts;

use App\Models\PushSubscription;
use App\Support\Push\PushMessage;

/**
 * Step B-7.4 — the single seam for delivering a web-push notification to one browser subscription.
 * Keeping delivery behind a contract means the VAPID transport (a `minishlink/web-push` client, a
 * hosted push gateway, …) can be swapped, and tests can fake the whole thing.
 */
interface PushNotifier
{
    /**
     * Whether push is turned on and has the VAPID key pair it needs. The opt-in UI and the reminder
     * command both short-circuit when this is false, so nothing is attempted half-configured.
     */
    public function isConfigured(): bool;

    /**
     * The VAPID public key the browser needs to create a subscription (`applicationServerKey`), or
     * null when push isn't configured.
     */
    public function publicKey(): ?string;

    /**
     * Deliver one message to one subscription. Returns false on any failure (never throws), so a
     * single dead subscription can't break a whole reminder sweep. A `gone` result means the caller
     * should drop the subscription (the browser unsubscribed) — see {@see PushDeliveryResult}.
     */
    public function send(PushSubscription $subscription, PushMessage $message): PushDeliveryResult;
}
