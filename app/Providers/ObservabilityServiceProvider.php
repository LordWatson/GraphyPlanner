<?php

namespace App\Providers;

use App\Contracts\ErrorTracker;
use App\Contracts\OpsAlerter;
use App\Models\User;
use App\Services\Observability\NotifyingOpsAlerter;
use App\Services\Observability\NullErrorTracker;
use App\Services\Observability\SentryErrorTracker;
use App\Support\Observability\TraceContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Step I-16.1 / M-1.1 — error tracking, alerts, trace ids and the Pulse dashboard gate.
 *
 * - `ErrorTracker` is Sentry when a DSN is configured, otherwise a no-op.
 * - Jobs dispatched outside a request (the scheduler) and console commands get their own
 *   correlation id; jobs dispatched from a request keep the request's, because `Context` is
 *   carried in the job payload and hydrated before these listeners run.
 * - `/pulse` (slow queries, slow jobs, slow outgoing requests, exceptions) is super-admin only,
 *   like Horizon.
 * - `AlertOnFailedPublishingJob` is picked up by event discovery (`app/Listeners`).
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorTracker::class, function () {
            return filled(config('sentry.dsn'))
                ? $this->app->make(SentryErrorTracker::class)
                : $this->app->make(NullErrorTracker::class);
        });

        $this->app->bind(OpsAlerter::class, NotifyingOpsAlerter::class);
    }

    public function boot(): void
    {
        Event::listen(JobProcessing::class, fn () => TraceContext::ensureCorrelationId());
        Event::listen(CommandStarting::class, fn () => TraceContext::ensureCorrelationId());

        Gate::define('viewPulse', fn (?User $user = null): bool => $user?->isSuperAdmin() ?? false);
    }
}
