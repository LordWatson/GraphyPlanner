<?php

namespace App\Contracts;

/**
 * Step I-16.1 / M-1.1 — tells whoever is on call that something operational broke (a publishing
 * job failed for good, a webhook arrived with a bad signature). Throttled per `$key`, so one
 * outage is one alert. Implementations must never put secrets in the alert.
 */
interface OpsAlerter
{
    /**
     * @param  string  $key  Stable identifier for throttling, e.g. `publishing.job_failed`.
     * @param  array<string, mixed>  $context  Ids only (post, target, org, correlation).
     */
    public function alert(string $key, string $message, array $context = []): void;
}
