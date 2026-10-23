<?php

namespace App\Console\Commands;

use App\Actions\Business\SendWeeklyInsightsDigestAction;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Step B-8.2 — sends each business workspace its weekly Insights digest on Monday morning in the
 * brand's own timezone.
 *
 * Runs hourly (see routes/console.php) and only acts on orgs whose local day is Monday and local
 * hour matches `analytics.insights_digest.send_hour`, so every timezone is caught from one schedule.
 * Idempotent: a per-org, per-ISO-week cache marker means a second run in the same hour sends
 * nothing twice. Suspended orgs and those that opted out ({@see Organization::wantsInsightsDigest()})
 * are skipped.
 */
class SendWeeklyInsightsDigestCommand extends Command
{
    protected $signature = 'business:send-insights-digest';

    protected $description = 'Send the weekly Insights email digest to each business workspace (B-8.2)';

    public function handle(SendWeeklyInsightsDigestAction $action): int
    {
        $targetHour = max(0, min(23, (int) config('analytics.insights_digest.send_hour', 8)));
        $sent = 0;

        Organization::query()
            ->ofType(OrganizationType::Business)
            ->whereNull('suspended_at')
            ->where('insights_digest_opt_out', false)
            ->whereNotNull('onboarding_completed_at')
            ->with('primaryBrand')
            ->lazyById(100)
            ->each(function (Organization $org) use ($action, $targetHour, &$sent): void {
                $timezone = $org->default_timezone ?: 'UTC';
                $now = CarbonImmutable::now($timezone);

                if ($now->dayOfWeekIso !== CarbonImmutable::MONDAY || $now->hour !== $targetHour) {
                    return;
                }

                $marker = "business:insights-digest-sent:{$org->id}:".$now->isoFormat('GGGG-[W]WW');

                if (Cache::has($marker)) {
                    return;
                }

                try {
                    if ($action($org)) {
                        $sent++;
                    }

                    // Mark the week as handled whether or not an email went out (skipped = no
                    // activity), so the sweep doesn't re-evaluate this org every hour of Monday.
                    Cache::put($marker, true, now()->addDays(2));
                } catch (\Throwable $e) {
                    Log::error('Weekly insights digest failed for a workspace', [
                        'org_id' => $org->id,
                        'exception' => $e::class,
                    ]);
                }
            });

        $this->info("Sent {$sent} weekly insights digest(s).");

        return self::SUCCESS;
    }
}
