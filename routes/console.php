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
