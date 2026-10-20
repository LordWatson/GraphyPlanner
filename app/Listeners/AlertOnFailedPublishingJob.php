<?php

namespace App\Listeners;

use App\Contracts\OpsAlerter;
use App\Support\Observability\TraceContext;
use App\Support\Queues;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;

/**
 * Step I-16.1 / M-1.1 — a job on the `publishing` queue failed for good (out of retries, or
 * failed outright): report the exception to error tracking (with the job's hydrated `Context`,
 * so it carries the post id and the correlation id of the request that scheduled it) and alert
 * on call. The handler skips duplicate reports, so the worker reporting it too sends it once.
 */
class AlertOnFailedPublishingJob
{
    public function __construct(private readonly OpsAlerter $alerter) {}

    public function handle(JobFailed $event): void
    {
        if (self::queueOf($event->connectionName, $event->job) !== Queues::PUBLISHING) {
            return;
        }

        report($event->exception);

        $this->alerter->alert('publishing.job_failed', 'A publishing job failed', array_filter([
            'job' => $event->job->resolveName(),
            'error' => $event->exception->getMessage(),
            TraceContext::POST_ID => Context::get(TraceContext::POST_ID),
            TraceContext::POST_TARGET_ID => Context::get(TraceContext::POST_TARGET_ID),
            TraceContext::ORG_ID => Context::get(TraceContext::ORG_ID),
        ], fn ($value) => $value !== null));
    }

    /**
     * The queue the job was sent to. Real drivers know it; the `sync` driver always says "sync",
     * so there it's read from the command itself (local runs and tests only).
     */
    private static function queueOf(string $connection, Job $job): ?string
    {
        if ($connection !== 'sync') {
            return $job->getQueue();
        }

        $command = $job->payload()['data']['command'] ?? null;
        $instance = is_string($command) ? unserialize($command) : null;

        return is_object($instance) && isset($instance->queue) && is_string($instance->queue) ? $instance->queue : null;
    }
}
