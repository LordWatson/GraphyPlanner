<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Support\Scheduling\SchedulerHeartbeat;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Step I-1.8 — every scheduled command is overlap-protected, runs on one server, is known to the
 * heartbeat registry, and the super admin health tile flags a command that stopped running.
 */
class SchedulerSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_scheduled_command_has_overlap_protection_and_runs_on_one_server(): void
    {
        $events = $this->scheduledCommands();

        $this->assertNotEmpty($events);

        foreach ($events as $name => $event) {
            $this->assertTrue($event->withoutOverlapping, "{$name} can overlap itself.");
            $this->assertTrue($event->onOneServer, "{$name} can run on several servers at once.");
        }
    }

    public function test_every_scheduled_command_is_registered_with_the_heartbeat(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(SchedulerHeartbeat::COMMANDS),
            array_keys($this->scheduledCommands()),
        );
    }

    public function test_each_heartbeat_interval_matches_the_real_schedule_cadence(): void
    {
        // The super admin dashboard marks a command stale after two of its registered intervals, so
        // a heartbeat interval that disagrees with the actual cron cadence silently breaks staleness
        // detection (e.g. a job that now runs every 30 min still registered as daily).
        foreach ($this->scheduledCommands() as $name => $event) {
            $cron = new CronExpression($event->getExpression());
            $first = $cron->getNextRunDate(new \DateTime('2026-01-05 00:00:00'));
            $second = $cron->getNextRunDate($first);
            $actualMinutes = (int) round(($second->getTimestamp() - $first->getTimestamp()) / 60);

            $this->assertSame(
                SchedulerHeartbeat::COMMANDS[$name]['interval_minutes'],
                $actualMinutes,
                "{$name} is scheduled every {$actualMinutes} min but its heartbeat interval says otherwise.",
            );
        }
    }

    public function test_a_successful_run_records_a_heartbeat(): void
    {
        $this->travelTo('2026-10-18 09:00:00');

        // Fire the event's completion callbacks as the scheduler does after a run (exit code 0 =
        // success) — without spawning the real command in a subprocess.
        $event = $this->scheduledCommands()['publishing:poll-pending-statuses'];
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);
        $this->assertNull(SchedulerHeartbeat::lastRanAt('publishing:poll-pending-statuses'), 'A failed run must not count.');

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        $this->assertSame('2026-10-18 09:00:00', SchedulerHeartbeat::lastRanAt('publishing:poll-pending-statuses')?->format('Y-m-d H:i:s'));
    }

    public function test_a_command_goes_stale_after_missing_two_intervals(): void
    {
        $this->travelTo('2026-10-18 09:00:00');
        SchedulerHeartbeat::record('publishing:poll-pending-statuses');

        $this->travelTo('2026-10-18 09:09:00');
        $this->assertFalse($this->statusFor('publishing:poll-pending-statuses')['stale']);

        $this->travelTo('2026-10-18 09:11:00');
        $this->assertTrue($this->statusFor('publishing:poll-pending-statuses')['stale']);

        // Never ran at all → stale.
        $this->assertTrue($this->statusFor('ai-images:prune')['stale']);
    }

    public function test_the_super_admin_dashboard_shows_scheduler_health_and_queue_backlog(): void
    {
        SchedulerHeartbeat::record('publishing:poll-pending-statuses');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('health.schedulers', count(SchedulerHeartbeat::COMMANDS))
                ->where('health.schedulers.1.command', 'publishing:poll-pending-statuses')
                ->where('health.schedulers.1.stale', false)
                ->has('health.queue.failed'));
    }

    /**
     * @return array<string, Event>
     */
    private function scheduledCommands(): array
    {
        Artisan::call('schedule:list'); // make sure routes/console.php has been loaded

        $events = [];

        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (preg_match('/artisan["\']?\s+["\']?([\w:-]+)/', (string) $event->command, $matches)) {
                $events[$matches[1]] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array{command: string, label: string, interval_minutes: int, last_ran_at: string|null, stale: bool}
     */
    private function statusFor(string $command): array
    {
        return collect(SchedulerHeartbeat::status())->firstWhere('command', $command);
    }
}
