<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SendScheduledOrganizationReportsAction;
use App\Enums\ReportSchedule;
use App\Enums\Role;
use App\Mail\OrganizationReportMail;
use App\Models\OrganizationReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Analytics\Concerns\BuildsAnalyticsFixtures;
use Tests\TestCase;

/**
 * Step 17.8 — scheduled report emails with a faked mailer and a frozen clock: once per period in
 * the org's timezone, suspended orgs skipped, departed recipients skipped, and each recipient's
 * email built for their own role.
 */
class ScheduledReportsTest extends TestCase
{
    use BuildsAnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAnalyticsFixtures();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownAnalyticsFixtures();

        parent::tearDown();
    }

    /**
     * @param  list<int>  $recipients
     */
    private function scheduled(ReportSchedule $schedule, array $recipients, array $attributes = []): OrganizationReport
    {
        return OrganizationReport::factory()->scheduled($schedule, $recipients)->create([
            'org_id' => $this->org->id,
            'created_by' => $this->owner->id,
            'name' => 'Studio pulse',
            ...$attributes,
        ]);
    }

    private function send(string $at): int
    {
        Carbon::setTestNow($at);

        return app(SendScheduledOrganizationReportsAction::class)(CarbonImmutable::parse($at, 'UTC'));
    }

    public function test_a_weekly_report_is_sent_once_per_week(): void
    {
        $report = $this->scheduled(ReportSchedule::Weekly, [$this->owner->id]);

        // Saturday 17 Oct: never sent → due now.
        $this->assertSame(1, $this->send('2026-10-17 07:00:00'));
        Mail::assertSentCount(1);

        // Sunday — same week (Mon 12 Oct onwards), nothing more.
        $this->assertSame(0, $this->send('2026-10-18 07:00:00'));
        Mail::assertSentCount(1);

        // Monday 19 Oct (London) — a new week.
        $this->assertSame(1, $this->send('2026-10-19 07:00:00'));
        Mail::assertSentCount(2);
        $this->assertSame('2026-10-19 07:00:00', $report->refresh()->last_sent_at?->format('Y-m-d H:i:s'));

        $this->artisan('analytics:send-scheduled-reports')->expectsOutput('Sent 0 scheduled report(s).')->assertSuccessful();
    }

    public function test_a_monthly_report_follows_the_organizations_calendar(): void
    {
        $this->scheduled(ReportSchedule::Monthly, [$this->owner->id], ['last_sent_at' => Carbon::parse('2026-10-01 08:00:00')]);

        $this->assertSame(0, $this->send('2026-10-31 22:00:00'));
        // 23:30 UTC on 31 Oct is still October in London (GMT after the clocks went back)…
        $this->assertSame(0, $this->send('2026-10-31 23:30:00'));

        // …but in Dubai it's already 1 November.
        $this->org->update(['default_timezone' => 'Asia/Dubai']);
        $this->assertSame(1, $this->send('2026-10-31 23:30:00'));
    }

    public function test_suspended_organizations_are_skipped(): void
    {
        $this->scheduled(ReportSchedule::Weekly, [$this->owner->id]);
        $this->org->forceFill(['suspended_at' => now()])->save();

        $this->assertSame(0, $this->send('2026-10-19 07:00:00'));
        Mail::assertNothingSent();
    }

    public function test_recipients_who_left_or_changed_role_are_skipped(): void
    {
        $leaver = User::factory()->for($this->org, 'organization')->role(Role::Strategist)->create();
        $foreign = User::query()->where('org_id', $this->otherOrg->id)->firstOrFail();
        $report = $this->scheduled(ReportSchedule::Weekly, [$this->owner->id, $leaver->id, $foreign->id, $this->reviewer->id]);
        $leaver->update(['org_id' => null]);

        $this->assertSame(1, $this->send('2026-10-19 07:00:00'));

        Mail::assertSentCount(1);
        Mail::assertSent(OrganizationReportMail::class, fn (OrganizationReportMail $mail) => $mail->hasTo($this->owner->email));
        Mail::assertNotSent(OrganizationReportMail::class, fn (OrganizationReportMail $mail) => $mail->hasTo($foreign->email) || $mail->hasTo($this->reviewer->email));
        $this->assertNotNull($report->refresh()->last_sent_at);
    }

    public function test_no_current_recipients_means_nothing_sent_and_no_stamp(): void
    {
        $report = $this->scheduled(ReportSchedule::Weekly, [$this->reviewer->id]);

        $this->assertSame(0, $this->send('2026-10-19 07:00:00'));
        Mail::assertNothingSent();
        $this->assertNull($report->refresh()->last_sent_at);
    }

    public function test_each_recipient_gets_their_own_role_scoped_run(): void
    {
        $this->scheduled(ReportSchedule::Weekly, [$this->owner->id, $this->designer->id]);

        $this->send('2026-10-19 07:00:00');

        Mail::assertSent(OrganizationReportMail::class, function (OrganizationReportMail $mail) {
            if (! $mail->hasTo($this->designer->email)) {
                return false;
            }

            $html = $mail->render();

            return $mail->summary['revenue'] === null
                && $mail->summary['ai']['cost_estimate'] === null
                && ! str_contains($mail->csv, 'revenue,')
                && ! str_contains($mail->csv, 'Invoiced')
                && ! str_contains($html, 'Invoiced')
                && str_starts_with((string) $mail->pdf, '%PDF')
                && str_contains($html, 'Hi Dee');
        });

        Mail::assertSent(OrganizationReportMail::class, function (OrganizationReportMail $mail) {
            return $mail->hasTo($this->owner->email)
                && $mail->summary['revenue'] !== null
                && str_contains($mail->csv, 'revenue,invoiced,2000,AED')
                && str_contains($mail->render(), 'Invoiced · GBP');
        });
    }

    public function test_the_mail_carries_both_attachments(): void
    {
        $this->scheduled(ReportSchedule::Weekly, [$this->owner->id]);

        $this->send('2026-10-19 07:00:00');

        Mail::assertSent(OrganizationReportMail::class, function (OrganizationReportMail $mail) {
            $mail->build();
            $names = array_column($mail->rawAttachments, 'name');

            return $names === ['studio-pulse-2026-10-19.csv', 'studio-pulse-2026-10-19.pdf']
                && $mail->subject === 'Studio pulse — your weekly analytics';
        });
    }
}
