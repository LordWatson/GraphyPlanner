<?php

namespace App\Listeners;

use App\Contracts\OpsAlerter;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * Audit finding 12 step 5 — Horizon noticed a queue whose oldest job has been waiting longer than
 * its `horizon.waits` threshold; this turns that into an ops alert through the same throttled
 * channel as a failed publish.
 *
 * Horizon can send its own notification for this, but only once a mail/Slack route is registered
 * on it, and nobody is watching the dashboard at 3am. `NotifyingOpsAlerter` already throttles per
 * key and sends the mail inline rather than queueing it — which matters here, since the alert is
 * about the queue.
 *
 * A `reports` wait past one sweep cycle is the failure finding 12 exists to catch: the engagement
 * sweep is no longer keeping up, and analytics are going stale.
 */
class AlertOnLongQueueWait
{
    public function __construct(private readonly OpsAlerter $alerter) {}

    public function handle(LongWaitDetected $event): void
    {
        $this->alerter->alert(
            'queue.long_wait:'.$event->queue,
            sprintf('Jobs on the %s queue have been waiting %d seconds.', $event->queue, $event->seconds),
            ['connection' => $event->connection, 'queue' => $event->queue, 'seconds' => $event->seconds],
        );
    }
}
