<?php

namespace App\Actions\Analytics;

use App\Enums\ClientReportStatus;
use App\Mail\ClientReportPublishedMail;
use App\Models\ClientReport;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Support\ClientReports\ClientReportRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Step M-3.3 — publish a draft monthly report: rebuild its figures one last time and **freeze**
 * them, show it in the client's portal, and (after commit) email it with the branded PDF to the
 * client's contacts — its portal reviewers plus the client's approval email when that isn't
 * already one of them. `$user` is null when the scheduler publishes an automatic report.
 */
class PublishClientReportAction
{
    public function __construct(private readonly BuildClientReportSnapshotAction $build) {}

    public function __invoke(ClientReport $report, ?User $user = null): ClientReport
    {
        $report = DB::transaction(function () use ($report, $user) {
            /** @var ClientReport $locked */
            $locked = ClientReport::query()->withoutGlobalScope(OrganizationScope::class)->lockForUpdate()->findOrFail($report->id);

            if ($locked->isPublished()) {
                throw ValidationException::withMessages(['report' => 'This report has already been published.']);
            }

            $client = $locked->client;

            if ($client === null || $client->trashed()) {
                throw ValidationException::withMessages(['report' => 'This client has been archived.']);
            }

            $locked->update([
                'snapshot' => ($this->build)($client, CarbonImmutable::parse($locked->period_start->toDateString())),
                'snapshot_at' => now(),
                'status' => ClientReportStatus::Published,
                'published_at' => now(),
                'published_by' => $user?->id,
            ]);

            $recipients = ClientReportRecipients::for($client);

            DB::afterCommit(function () use ($locked, $recipients) {
                foreach ($recipients as $recipient) {
                    Mail::to($recipient['email'])->queue(new ClientReportPublishedMail($locked, $recipient['name']));
                }

                $locked->forceFill(['sent_at' => $recipients === [] ? null : now(), 'recipients_count' => count($recipients)])->saveQuietly();
            });

            return $locked;
        });

        Log::info('Client report published', [
            'org_id' => $report->org_id,
            'client_id' => $report->client_id,
            'client_report_id' => $report->id,
            'period' => $report->period_start->toDateString(),
            'user_id' => $user?->id,
            'automatic' => $user === null,
        ]);

        return $report;
    }
}
