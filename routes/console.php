<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Warns organization users by email about posts due to publish in the next few days.
Schedule::command('posts:send-upcoming-reminders')->dailyAt('08:00');

// Step 1.5 fallback: catches any post stuck in `publishing` because the Upload-Post webhook
// never arrived.
Schedule::command('publishing:poll-pending-statuses')->everyFiveMinutes();

// Step 1.6: re-checks every connected social account's token health so an expired token is
// caught even if nothing else touches that account.
Schedule::command('social-accounts:check-health')->hourly();

// Step 2.5: nightly AI "Daily Brief" per active client (trends + fresh post ideas), emailed to
// Owners/Strategists — idempotent per client/day, see GenerateDailyBriefsAction.
Schedule::command('ai:generate-daily-briefs')->dailyAt('06:00');

// Step 16.13: deletes files of AI Image Studio variants discarded or never selected after
// `ai.images.prune_after_days` (30 by default). Images attached to a post are always kept.
Schedule::command('ai-images:prune')->dailyAt('03:30');

// Step 17.8: emails saved organization reports on their weekly (Monday) / monthly (1st) schedule,
// at most once per period per report, skipping suspended orgs — see
// SendScheduledOrganizationReportsAction.
Schedule::command('analytics:send-scheduled-reports')->dailyAt('07:00');
