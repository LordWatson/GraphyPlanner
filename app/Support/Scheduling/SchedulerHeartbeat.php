<?php

namespace App\Support\Scheduling;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step I-1.8 — every scheduled command records a heartbeat when it finishes (wired in
 * `routes/console.php`), and the super admin dashboard reads them back: a command is **stale**
 * when it hasn't run within two of its intervals, which usually means the scheduler (cron) or the
 * command itself has stopped. Also reports the queue backlog (pending + failed jobs).
 *
 * `COMMANDS` must list every scheduled command with its interval in minutes —
 * `SchedulerSafetyTest` fails if one is scheduled without an entry here.
 */
final class SchedulerHeartbeat
{
    /**
     * @var array<string, array{label: string, interval_minutes: int}>
     */
    public const COMMANDS = [
        'posts:send-upcoming-reminders' => ['label' => 'Upcoming-post reminders', 'interval_minutes' => 1440],
        'publishing:poll-pending-statuses' => ['label' => 'Publish status poll', 'interval_minutes' => 5],
        'social-accounts:check-health' => ['label' => 'Social account health check', 'interval_minutes' => 60],
        'ai:generate-daily-briefs' => ['label' => 'Daily AI briefs', 'interval_minutes' => 1440],
        'ai-images:prune' => ['label' => 'AI image prune', 'interval_minutes' => 1440],
        'clients:recompute-health' => ['label' => 'Client health sweep', 'interval_minutes' => 1440],
        'analytics:send-scheduled-reports' => ['label' => 'Scheduled reports', 'interval_minutes' => 1440],
    ];

    public static function record(string $command): void
    {
        Cache::forever(self::key($command), Carbon::now()->toIso8601String());
    }

    public static function lastRanAt(string $command): ?Carbon
    {
        $value = Cache::get(self::key($command));

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /**
     * @return list<array{command: string, label: string, interval_minutes: int, last_ran_at: string|null, stale: bool}>
     */
    public static function status(): array
    {
        $rows = [];

        foreach (self::COMMANDS as $command => $meta) {
            $lastRanAt = self::lastRanAt($command);

            $rows[] = [
                'command' => $command,
                'label' => $meta['label'],
                'interval_minutes' => $meta['interval_minutes'],
                'last_ran_at' => $lastRanAt?->toIso8601String(),
                'stale' => $lastRanAt === null || $lastRanAt->lt(Carbon::now()->subMinutes(2 * $meta['interval_minutes'])),
            ];
        }

        return $rows;
    }

    /**
     * @return array{pending: int|null, failed: int}
     */
    public static function queueBacklog(): array
    {
        $pending = null;

        // Only the database driver can be counted without a vendor-specific API.
        if (config('queue.default') === 'database') {
            try {
                $pending = DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
            } catch (Throwable) {
                $pending = null;
            }
        }

        try {
            $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            $failed = 0;
        }

        return ['pending' => $pending, 'failed' => $failed];
    }

    private static function key(string $command): string
    {
        return 'scheduler-heartbeat:'.$command;
    }
}
