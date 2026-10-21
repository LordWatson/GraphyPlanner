<?php

namespace App\Actions\Analytics;

use App\Enums\ClientReportSchedule;
use App\Enums\ClientStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Notifications\ClientReportReady;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Step M-3.3 (PRD-001 gap #9) — from `analytics.client_reports.send_day` at `send_hour` each
 * month, in each organization's timezone, prepare last month's report for every active client whose schedule
 * isn't `off`:
 *
 * - `automatic` → published straight away (portal + email to the client's contacts);
 * - `review` → a draft, and the client's owner (else the org's Owners and Strategists) get a bell
 *   notification to add commentary and publish.
 *
 * Idempotent: a month that already has a report for the client is left alone (a published one is
 * never re-sent), so a re-run or a missed day can't double-email. Suspended orgs are skipped. One
 * client failing is logged and never stops the rest.
 */
class SendMonthlyClientReportsAction
{
    public function __construct(
        private readonly PrepareClientReportAction $prepare,
        private readonly PublishClientReportAction $publish,
    ) {}

    /**
     * @return array{prepared: int, published: int, failed: int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $day = max(1, min(28, (int) config('analytics.client_reports.send_day', 1)));
        $hour = max(0, min(23, (int) config('analytics.client_reports.send_hour', 9)));
        $totals = ['prepared' => 0, 'published' => 0, 'failed' => 0];

        Organization::query()->active()->orderBy('id')->each(function (Organization $organization) use ($now, $day, $hour, &$totals) {
            $timezone = in_array($organization->default_timezone, timezone_identifiers_list(), true) ? $organization->default_timezone : 'UTC';
            $local = $now->setTimezone($timezone);

            // Not before the report day's morning, so the nightly engagement sync has run.
            if ($local->day < $day || ($local->day === $day && $local->hour < $hour)) {
                return;
            }

            // Catch up within the month: the report for the month just ended is due from send day on.
            $month = $local->startOfMonth()->subMonthNoOverflow();

            Client::query()
                ->withoutGlobalScope(OrganizationScope::class)
                ->where('org_id', $organization->id)
                ->where('status', ClientStatus::Active)
                ->where('report_schedule', '!=', ClientReportSchedule::Off->value)
                // A client added after the month ended has nothing to report for it.
                ->where('created_at', '<', $month->endOfMonth()->setTimezone('UTC'))
                ->whereDoesntHave('reports', fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)->whereDate('period_start', $month->toDateString()))
                ->with('organization')
                ->orderBy('id')
                ->each(function (Client $client) use ($month, &$totals) {
                    try {
                        $report = ($this->prepare)($client, $month);
                        $totals['prepared']++;

                        if ($client->report_schedule === ClientReportSchedule::Automatic) {
                            ($this->publish)($report);
                            $totals['published']++;

                            return;
                        }

                        $this->notifyTeam($client, $report);
                    } catch (Throwable $e) {
                        $totals['failed']++;
                        Log::error('Monthly client report failed', [
                            'org_id' => $client->org_id,
                            'client_id' => $client->id,
                            'period' => $month->toDateString(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
        });

        Log::info('Monthly client reports run finished', $totals);

        return $totals;
    }

    private function notifyTeam(Client $client, ClientReport $report): void
    {
        $owner = $client->owner_user_id === null ? null : User::query()->whereKey($client->owner_user_id)->where('org_id', $client->org_id)->first();

        $recipients = $owner !== null && in_array($owner->role, [Role::Owner, Role::Strategist], true)
            ? collect([$owner])
            : User::query()->where('org_id', $client->org_id)->whereIn('role', [Role::Owner, Role::Strategist])->get();

        Notification::send($recipients, new ClientReportReady($report));
    }
}
