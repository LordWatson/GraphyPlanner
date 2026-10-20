<?php

namespace App\Services\Observability;

use App\Contracts\OpsAlerter;
use App\Mail\OpsAlertMail;
use App\Support\Observability\SecretScrubber;
use App\Support\Observability\TraceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Step I-16.1 / M-1.1 — logs the alert at `critical` (on `observability.alerts.log_channel`,
 * e.g. `slack`) and emails `observability.alerts.mail_to`. The same key alerts at most once per
 * `throttle_minutes`; repeats inside the window are only logged at `warning`, so nothing is lost
 * from the logs but nobody gets paged twice for one outage.
 *
 * The mail is sent inline, not queued: the alert may be about the queue itself.
 */
class NotifyingOpsAlerter implements OpsAlerter
{
    public function alert(string $key, string $message, array $context = []): void
    {
        $context = SecretScrubber::scrub($context + array_filter([
            TraceContext::CORRELATION_ID => TraceContext::correlationId(),
        ]));

        $minutes = max(1, (int) config('observability.alerts.throttle_minutes', 10));

        if (! Cache::add('ops-alert:'.$key, true, now()->addMinutes($minutes))) {
            Log::warning("Ops alert (throttled) [{$key}]: {$message}", $context);

            return;
        }

        $channel = config('observability.alerts.log_channel');
        $logger = is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->critical("Ops alert [{$key}]: {$message}", $context);

        $to = config('observability.alerts.mail_to');

        if (! is_string($to) || $to === '') {
            return;
        }

        try {
            Mail::to(array_map('trim', explode(',', $to)))->send(new OpsAlertMail($key, $message, $context));
        } catch (Throwable $e) {
            Log::error('Ops alert: could not send the alert email', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
