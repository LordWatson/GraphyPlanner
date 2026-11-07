<?php

namespace App\Console\Commands;

use App\Contracts\OpsAlerter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Step GTM-7.2 (closes CG-9.2) — the quarterly reminder that the competitor figures on the
 * marketing site have a shelf life.
 *
 * It deliberately checks **nothing automatically.** Vendors move prices behind marketing pages,
 * paywalls and A/B tests; a scraper would give a confident wrong answer, and a wrong price on a
 * public comparison page is worse than no page. So this nags a human, names the pages that will go
 * stale, and stops nagging when `COMPETITORS_LAST_CHECKED` moves.
 *
 * Alerting rather than emailing directly: `OpsAlerter` already throttles, logs and reaches whoever
 * is on call, so one stale quarter is one alert rather than one a day.
 */
class ReviewCompetitorsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'competitors:review-due {--force : Alert even when the figures are still fresh}';

    /**
     * @var string
     */
    protected $description = 'Step GTM-7.2: alert when the competitor figures behind the comparison pages are due a re-check';

    public function handle(OpsAlerter $alerter): int
    {
        $lastChecked = CarbonImmutable::parse((string) config('competitors.last_checked'));
        $every = max(1, (int) config('competitors.review_every_days', 90));
        $dueOn = $lastChecked->addDays($every);
        $ageDays = (int) $lastChecked->diffInDays(now());

        if (! $this->option('force') && now()->lt($dueOn)) {
            $this->info(sprintf(
                'Competitor figures were checked %d day(s) ago; next review %s.',
                $ageDays,
                $dueOn->toDateString(),
            ));

            return self::SUCCESS;
        }

        $rivals = array_map(
            fn (array $rival) => $rival['name'].' ('.$rival['price'].')',
            (array) config('competitors.rivals', []),
        );

        $alerter->alert('marketing.competitor_review_due', sprintf(
            'The competitor figures on the comparison pages were last checked on %s (%d days ago). '
            .'Re-read each vendor\'s own pricing page, update config/competitors.php, and move '
            .'COMPETITORS_LAST_CHECKED. Currently quoted: %s.',
            $lastChecked->toDateString(),
            $ageDays,
            implode('; ', $rivals),
        ), [
            'last_checked' => $lastChecked->toDateString(),
            'age_days' => $ageDays,
            'rivals' => array_keys((array) config('competitors.rivals', [])),
        ]);

        $this->warn("Competitor figures are {$ageDays} days old — a review alert has gone out.");

        return self::SUCCESS;
    }
}
