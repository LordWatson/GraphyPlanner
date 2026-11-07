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
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Step I-16.1 / M-1.1 — error tracking, alerts, trace ids and the Pulse dashboard gate.
 *
 * - `ErrorTracker` is Sentry when a DSN is configured, otherwise a no-op.
 * - Jobs dispatched outside a request (the scheduler) and console commands get their own
 *   correlation id; jobs dispatched from a request keep the request's, because `Context` is
 *   carried in the job payload and hydrated before these listeners run.
 * - `/pulse` (slow queries, slow jobs, slow outgoing requests, exceptions) is super-admin only,
 *   like Horizon.
 * - `/up` fails while the configured asset disk cannot store a file (see
 *   {@see assetDiskMisconfiguration()}), so a half-finished S3 switch never reaches users.
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

        // `/up` must fail while asset storage is misconfigured, so a bad deploy is caught by the
        // load balancer instead of by the first person who uploads a file.
        Event::listen(DiagnosingHealth::class, function (): void {
            if ($reason = self::assetDiskMisconfiguration()) {
                throw new RuntimeException($reason);
            }
        });

        Gate::define('viewPulse', fn (?User $user = null): bool => $user?->isSuperAdmin() ?? false);
    }

    /**
     * Why the configured asset disk could not store a file, or null when it looks usable.
     *
     * Only the cheap, no-network checks: that the disk named by `assets.disk` exists at all, and
     * that an `s3` one has been given its bucket and credentials. `ASSETS_DISK=s3` with the AWS
     * variables missing used to fail at the first upload — a 500 for a user, long after the deploy
     * that caused it.
     */
    public static function assetDiskMisconfiguration(): ?string
    {
        $disk = (string) config('assets.disk');
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return "assets.disk is \"{$disk}\", which is not a disk in config/filesystems.php.";
        }

        if (($config['driver'] ?? null) !== 's3') {
            return null;
        }

        $missing = array_keys(array_filter(
            ['bucket' => $config['bucket'] ?? null, 'key' => $config['key'] ?? null, 'secret' => $config['secret'] ?? null],
            fn ($value) => blank($value),
        ));

        return $missing === []
            ? null
            : sprintf('The "%s" asset disk is missing: %s.', $disk, implode(', ', $missing));
    }
}
