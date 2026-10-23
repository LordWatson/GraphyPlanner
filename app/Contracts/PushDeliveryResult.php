<?php

namespace App\Contracts;

/**
 * Step B-7.4 — the outcome of a single push delivery, so the caller knows whether to retry, give
 * up, or prune a now-dead subscription.
 */
enum PushDeliveryResult
{
    /** Accepted by the push service. */
    case Delivered;

    /** A transient failure (network, 5xx) — the subscription is kept, the message is simply lost. */
    case Failed;

    /** The push service says the subscription is gone (404/410) — the caller should delete it. */
    case Gone;

    /** Push isn't configured, so nothing was attempted. */
    case Skipped;
}
