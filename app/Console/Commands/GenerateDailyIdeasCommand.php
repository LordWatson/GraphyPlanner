<?php

namespace App\Console\Commands;

use App\Actions\AI\GenerateDailyIdeaAction;
use App\Enums\OrganizationType;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.1 — fills each business brand's "Idea of the day" at ~6am in the brand's own timezone.
 *
 * Runs hourly (see routes/console.php) and only acts on orgs whose local hour matches
 * `ai.ideas.daily_hour`, so every timezone gets its idea early in its own morning from a single
 * daily schedule. Idempotent: {@see GenerateDailyIdeaAction} caches one idea per brand per local
 * day, so a second run in the same hour is a cheap cache hit. Suspended orgs are skipped (no AI
 * spend while locked out), and the whole command no-ops when AI is disabled.
 */
class GenerateDailyIdeasCommand extends Command
{
    protected $signature = 'business:generate-daily-ideas';

    protected $description = 'Generate the daily local/seasonal idea for each business brand (B-6.1)';

    public function handle(GenerateDailyIdeaAction $action): int
    {
        if (! (bool) config('ai.enabled', false)) {
            $this->info('AI is disabled — nothing to do.');

            return self::SUCCESS;
        }

        $targetHour = max(0, min(23, (int) config('ai.ideas.daily_hour', 6)));
        $generated = 0;

        Organization::query()
            ->ofType(OrganizationType::Business)
            ->whereNull('suspended_at')
            ->with('primaryBrand')
            ->lazyById(100)
            ->each(function (Organization $org) use ($action, $targetHour, &$generated): void {
                $timezone = $org->default_timezone ?: 'UTC';

                if ((int) CarbonImmutable::now($timezone)->hour !== $targetHour) {
                    return;
                }

                $brand = $org->primaryBrand;

                if ($brand === null) {
                    return;
                }

                try {
                    if ($action($brand, $timezone) !== null) {
                        $generated++;
                    }
                } catch (\Throwable $e) {
                    // One brand's failure must never stop the sweep.
                    Log::error('Daily idea generation failed for a brand', [
                        'org_id' => $org->id,
                        'client_id' => $brand->id,
                        'exception' => $e::class,
                    ]);
                }
            });

        $this->info("Generated {$generated} daily idea(s).");

        return self::SUCCESS;
    }
}
