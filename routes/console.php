<?php

use App\Support\Scheduling\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Step I-1.8: every scheduled command is protected against overlapping itself and against running
 * twice on a multi-server deploy (`onOneServer()` needs a shared cache store), and records a
 * heartbeat when it succeeds so the super admin dashboard can flag a stalled scheduler.
 */
$safely = function (Event $event, string $command): Event {
    return $event
        ->withoutOverlapping()
        ->onOneServer()
        ->onSuccess(fn () => SchedulerHeartbeat::record($command));
};

// Warns organization users by email about posts due to publish in the next few days.
$safely(Schedule::command('posts:send-upcoming-reminders')->dailyAt('08:00'), 'posts:send-upcoming-reminders');

// Step 1.5 fallback: catches any post stuck in `publishing` because the Upload-Post webhook
// never arrived.
$safely(Schedule::command('publishing:poll-pending-statuses')->everyFiveMinutes(), 'publishing:poll-pending-statuses');

// Step 1.6: re-checks every connected social account's token health so an expired token is
// caught even if nothing else touches that account.
$safely(Schedule::command('social-accounts:check-health')->hourly(), 'social-accounts:check-health');

// Step 2.5: nightly AI "Daily Brief" per active client (trends + fresh post ideas), emailed to
// Owners/Strategists — idempotent per client/day, see GenerateDailyBriefsAction.
$safely(Schedule::command('ai:generate-daily-briefs')->dailyAt('06:00'), 'ai:generate-daily-briefs');

// Step 16.13: deletes files of AI Image Studio variants discarded or never selected after
// `ai.images.prune_after_days` (30 by default). Images attached to a post are always kept.
$safely(Schedule::command('ai-images:prune')->dailyAt('03:30'), 'ai-images:prune');

// Step 17.8: emails saved organization reports on their weekly (Monday) / monthly (1st) schedule,
// at most once per period per report, skipping suspended orgs — see
// SendScheduledOrganizationReportsAction.
$safely(Schedule::command('analytics:send-scheduled-reports')->dailyAt('07:00'), 'analytics:send-scheduled-reports');

// Step I-2.5: nightly sweep behind the event-driven health recompute, so stored client health
// can never drift for long if a write path is missed.
$safely(Schedule::command('clients:recompute-health')->dailyAt('02:30'), 'clients:recompute-health');

// Campaigns enhancement C-4.4: assignees hear about deliverables due tomorrow or just overdue.
$safely(Schedule::command('campaigns:send-due-reminders')->dailyAt('08:15'), 'campaigns:send-due-reminders');
