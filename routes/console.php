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

// Step B-6.1: fills each business brand's "Idea of the day" at ~6am in the brand's own timezone.
// Hourly so every timezone is caught from one schedule; idempotent (cached per brand/local day).
$safely(Schedule::command('business:generate-daily-ideas')->hourlyAt(10), 'business:generate-daily-ideas');

// Step 16.13: deletes files of AI Image Studio variants discarded or never selected after
// `ai.images.prune_after_days` (30 by default). Images attached to a post are always kept.
$safely(Schedule::command('ai-images:prune')->dailyAt('03:30'), 'ai-images:prune');

// Step 18.13: soft-deletes archived grid plans untouched for 12 months (and the split slices
// nothing else uses) — see PruneGridPlansAction.
$safely(Schedule::command('grids:prune')->weeklyOn(0, '03:45'), 'grids:prune');

// Step 17.8: emails saved organization reports on their weekly (Monday) / monthly (1st) schedule,
// at most once per period per report, skipping suspended orgs — see
// SendScheduledOrganizationReportsAction.
$safely(Schedule::command('analytics:send-scheduled-reports')->dailyAt('07:00'), 'analytics:send-scheduled-reports');

// Step M-3.1: engagement metrics (followers, reach, per-post engagement) per organization, queued
// one job per org on the reports queue — see SyncOrganizationEngagementAction. Runs every 30 min so
// a freshly published post shows its stats quickly; the per-channel `min_interval_hours` guard keeps
// each sweep from re-reading the same post (and hammering the vendor) more than once an hour.
$safely(Schedule::command('analytics:sync-engagement')->everyThirtyMinutes(), 'analytics:sync-engagement');

// Step M-3.3: monthly client reports — on the report day (org timezone) last month's report is
// drafted for review or, for automatic clients, published and emailed. Hourly so every timezone
// gets its run early in its own morning; idempotent per client and month.
$safely(Schedule::command('analytics:send-client-reports')->hourlyAt(20), 'analytics:send-client-reports');

// Step I-2.5: sweep behind the event-driven health recompute, so stored client health can never
// drift for long if a write path is missed. Step M-3.4: hourly — v2 signals are time-based (an
// approval going overdue, a calendar gap opening up).
$safely(Schedule::command('clients:recompute-health')->hourlyAt(35), 'clients:recompute-health');

// Campaigns enhancement C-4.4: assignees hear about deliverables due tomorrow or just overdue.
$safely(Schedule::command('campaigns:send-due-reminders')->dailyAt('08:15'), 'campaigns:send-due-reminders');

// Step M-4.3: draft this period's retainer invoices for opted-in clients. Idempotent per client
// and period (computed in the org timezone), so a daily run only ever creates a period's draft
// once; the team reviews, finalizes and sends — nothing auto-sends. See GenerateRetainerDraftsAction.
$safely(Schedule::command('invoices:generate-retainer-drafts')->dailyAt('06:30'), 'invoices:generate-retainer-drafts');
