<?php

namespace App\Actions\Analytics;

use App\Enums\Role;
use App\Mail\OrganizationReportMail;
use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\AnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsCsv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Step 17.8 — emails every due scheduled report. Run daily by `analytics:send-scheduled-reports`.
 *
 * - **Once per period:** a weekly report is due from Monday 00:00 and a monthly one from the 1st,
 *   in the organization's own timezone; `last_sent_at` inside the current period means it's done.
 * - **Suspended organizations are skipped**, as daily briefs and reminders already are.
 * - **Per recipient:** the report runs once for each recipient with *them* as the viewer, so a
 *   Designer's email and attachments never contain revenue. Recipients who've left the org (or
 *   become a client reviewer) are skipped quietly.
 * - One failing report never stops the rest; it's logged and retried on the next daily run.
 *
 * Logs `org_id`, `report_id` and recipient counts — never addresses or figures.
 */
class SendScheduledOrganizationReportsAction
{
    public function __construct(
        private readonly RunOrganizationReportAction $run,
        private readonly GenerateOrganizationReportPdfAction $pdf,
    ) {}

    /**
     * @return int how many reports were sent
     */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        OrganizationReport::query()
            ->whereNotNull('schedule')
            ->whereHas('organization', fn (Builder $query) => $query->whereNull('suspended_at'))
            ->with('organization')
            ->orderBy('id')
            ->each(function (OrganizationReport $report) use ($now, &$sent) {
                if (! $this->isDue($report, $now)) {
                    return;
                }

                try {
                    $sent += $this->send($report, $now) ? 1 : 0;
                } catch (Throwable $exception) {
                    Log::error('Scheduled organization report failed', [
                        'org_id' => $report->org_id,
                        'report_id' => $report->id,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

        return $sent;
    }

    public function isDue(OrganizationReport $report, CarbonImmutable $now): bool
    {
        if ($report->schedule === null) {
            return false;
        }

        $local = $now->setTimezone($report->organization->default_timezone ?: 'UTC');
        $periodStart = $report->schedule->periodStart($local)->startOfDay();

        return $report->last_sent_at === null
            || CarbonImmutable::instance($report->last_sent_at)->lessThan($periodStart);
    }

    private function send(OrganizationReport $report, CarbonImmutable $now): bool
    {
        $recipients = $this->recipients($report->organization, $report->recipientIds());

        if ($recipients === []) {
            Log::info('Scheduled organization report skipped — no current recipients', [
                'org_id' => $report->org_id,
                'report_id' => $report->id,
            ]);

            return false;
        }

        foreach ($recipients as $recipient) {
            $summary = ($this->run)($report, $recipient, $now);

            Mail::to($recipient)->send(new OrganizationReportMail(
                $report,
                $recipient,
                $summary,
                $this->csv($summary),
                ($this->pdf)($report->name, $summary, $report->description),
            ));
        }

        $report->forceFill(['last_sent_at' => $now])->saveQuietly();

        Log::info('Scheduled organization report sent', [
            'org_id' => $report->org_id,
            'report_id' => $report->id,
            'schedule' => $report->schedule?->value,
            'recipient_count' => count($recipients),
            'skipped_recipients' => count($report->recipientIds()) - count($recipients),
        ]);

        return true;
    }

    /**
     * Current staff of the report's organization only.
     *
     * @param  list<int>  $ids
     * @return list<User>
     */
    private function recipients(Organization $organization, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(User::query()
            ->where('org_id', $organization->id)
            ->whereIn('id', $ids)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function csv(array $summary): string
    {
        return AnalyticsCsv::toString(OrganizationAnalyticsCsv::rows($summary));
    }
}
