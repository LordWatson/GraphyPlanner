<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SendMonthlyClientReportsAction;
use App\Enums\CampaignReportStatus;
use App\Enums\ClientReportSchedule;
use App\Enums\ClientReportStatus;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Mail\ClientReportPublishedMail;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ClientReportReady;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step M-3.3 / I-10.3 (Phase 8) — monthly client reports: client-safe figures (own client only,
 * no billing), role permissions, freezing on publish, emails to the client's contacts, the portal
 * (isolation: own client, published only) and the monthly schedule in the org's timezone.
 */
class ClientReportsTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    private Organization $org;

    private Client $client;

    private Client $other;

    private User $owner;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        // The clients exist well before the month being reported on.
        $this->travelTo('2026-08-20 12:00:00');
        Mail::fake();
        Notification::fake();

        $this->org = $this->engagementOrg('Asia/Dubai');
        $this->client = $this->engagementClient($this->org, 'Acme Cafe');
        $this->client->update(['approval_email' => 'Marketing@Acme.test', 'retainer_amount' => 1500, 'billing_cycle' => 'monthly']);
        $this->other = $this->engagementClient($this->org, 'Bolt Gym');
        $this->owner = $this->member(Role::Owner);
        $this->reviewer = User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create([
            'client_id' => $this->client->id,
            'email' => 'marketing@acme.test',
            'name' => 'Mira',
        ]);
        $this->travelTo('2026-10-21 12:00:00');
    }

    private function member(Role $role): User
    {
        return User::factory()->for($this->org, 'organization')->role($role)->create();
    }

    private function seedSeptember(): void
    {
        $ig = $this->engagementAccount($this->client, Platform::Instagram);
        $post = $this->publishedPost($this->client, [$ig], CarbonImmutable::parse('2026-09-12 10:00:00'), ['master_caption' => 'September menu']);
        $this->reading($post, $ig, ['likes' => 80, 'comments' => 20, 'reach' => 2000]);
        $this->followersOn($ig, '2026-09-01', 1000);
        $this->followersOn($ig, '2026-09-30', 1100);

        // Outside the month, and another client's post: neither may appear.
        $this->reading($this->publishedPost($this->client, [$ig], CarbonImmutable::parse('2026-10-02 10:00:00')), $ig, ['likes' => 500]);
        $bolt = $this->engagementAccount($this->other, Platform::TikTok);
        $this->reading($this->publishedPost($this->other, [$bolt], CarbonImmutable::parse('2026-09-15 10:00:00'), ['master_caption' => 'Bolt secret']), $bolt, ['likes' => 999]);

        Invoice::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => InvoiceStatus::Sent, 'amount' => 4321, 'currency' => 'GBP', 'issue_date' => '2026-09-10']);
    }

    private function prepareSeptember(?User $as = null): ClientReport
    {
        $this->actingAs($as ?? $this->owner)
            ->post(route('clients.reports.store', $this->client), ['month' => '2026-09'])
            ->assertRedirect();

        return ClientReport::query()->withoutOrgScope()->where('client_id', $this->client->id)->firstOrFail();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function keysOf(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $keys[] = (string) $key;

            if (is_array($value)) {
                array_push($keys, ...$this->keysOf($value));
            }
        }

        return $keys;
    }

    public function test_a_prepared_report_holds_only_this_clients_month_and_no_billing(): void
    {
        $this->seedSeptember();

        $report = $this->prepareSeptember();
        $snapshot = $report->snapshot;

        $this->assertSame(ClientReportStatus::Draft, $report->status);
        $this->assertSame('2026-09-01', $report->period_start->toDateString());
        $this->assertSame('September 2026', $snapshot['period']['label']);
        $this->assertSame('Asia/Dubai', $snapshot['period']['timezone']);
        $this->assertSame(1, $snapshot['posts']['published']);
        $this->assertSame(100, $snapshot['engagement']['totals']['engagement']);
        $this->assertEquals(5.0, $snapshot['engagement']['engagement_rate']);
        $this->assertSame(100, $snapshot['engagement']['followers']['growth']);
        $this->assertSame(['September menu'], array_column($snapshot['top_posts'], 'caption'));

        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Bolt', $json);
        $this->assertStringNotContainsString('4321', $json);
        $this->assertStringNotContainsString('1500', $json);
        $keys = $this->keysOf($snapshot);

        foreach (['invoiced', 'revenue', 'amount', 'retainer', 'currency', 'thumbnail_url', 'client_id', 'internal_turnaround', 'oldest_waiting'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "Snapshot must not carry `{$forbidden}`.");
        }
    }

    public function test_staff_roles_read_reports_but_only_owners_and_strategists_change_them(): void
    {
        $report = $this->prepareSeptember($this->member(Role::Strategist));

        foreach ([Role::Designer, Role::Viewer] as $role) {
            $member = $this->member($role);
            $this->actingAs($member)->get(route('clients.reports.index', $this->client))->assertOk()->assertInertia(fn ($page) => $page->where('can.prepare', false));
            $this->actingAs($member)->get(route('client-reports.show', $report))->assertOk()->assertInertia(fn ($page) => $page->where('can.publish', false));
            $this->actingAs($member)->post(route('clients.reports.store', $this->client), ['month' => '2026-08'])->assertForbidden();
            $this->actingAs($member)->put(route('client-reports.update', $report), ['commentary' => 'x'])->assertForbidden();
            $this->actingAs($member)->post(route('client-reports.publish', $report))->assertForbidden();
            $this->actingAs($member)->put(route('clients.report-schedule.update', $this->client), ['report_schedule' => 'off'])->assertForbidden();
        }

        // Client reviewers never reach the internal report studio — Step M-6.2 (I-8.1) redirects
        // them to their portal (they read published reports via `portal.reports`).
        $this->actingAs($this->reviewer)->get(route('clients.reports.index', $this->client))->assertRedirect();
        $this->actingAs($this->reviewer)->get(route('client-reports.show', $report))->assertRedirect();

        // Another organization's owner can't open it.
        $stranger = User::factory()->for($this->engagementOrg(), 'organization')->role(Role::Owner)->create();
        $this->actingAs($stranger)->get(route('client-reports.show', $report))->assertForbidden();
        $this->actingAs($stranger)->post(route('clients.reports.store', $this->client), ['month' => '2026-09'])->assertForbidden();
    }

    public function test_the_month_is_validated(): void
    {
        $this->actingAs($this->owner)->post(route('clients.reports.store', $this->client), ['month' => '2026-12'])->assertSessionHasErrors('month');
        $this->actingAs($this->owner)->post(route('clients.reports.store', $this->client), ['month' => 'September'])->assertSessionHasErrors('month');
        $this->assertSame(0, ClientReport::query()->withoutOrgScope()->count());
    }

    public function test_commentary_and_refresh_work_on_drafts_only(): void
    {
        $report = $this->prepareSeptember();

        $this->actingAs($this->owner)->put(route('client-reports.update', $report), ['commentary' => '  Reels flew this month.  '])->assertSessionHasNoErrors();
        $this->assertSame('Reels flew this month.', $report->fresh()?->commentary);
        $this->actingAs($this->owner)->put(route('client-reports.update', $report), ['commentary' => ''])->assertSessionHasNoErrors();
        $this->assertNull($report->fresh()?->commentary);

        // New data arrives: a draft picks it up on refresh.
        $this->seedSeptember();
        $this->actingAs($this->owner)->post(route('client-reports.refresh', $report))->assertSessionHasNoErrors();
        $this->assertSame(100, $report->fresh()?->snapshot['engagement']['totals']['engagement']);
    }

    public function test_publishing_freezes_the_figures_and_emails_the_clients_contacts_once(): void
    {
        $this->seedSeptember();
        User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->other->id, 'email' => 'boss@bolt.test']);
        $report = $this->prepareSeptember();

        $this->actingAs($this->owner)->post(route('client-reports.publish', $report))->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame(ClientReportStatus::Published, $report->status);
        $this->assertSame($this->owner->id, $report->published_by);
        // The reviewer and the approval email are the same person (case-insensitive): one email.
        $this->assertSame(1, $report->recipients_count);
        Mail::assertQueued(ClientReportPublishedMail::class, 1);
        Mail::assertQueued(ClientReportPublishedMail::class, fn ($mail) => $mail->hasTo('marketing@acme.test'));
        Mail::assertNotQueued(ClientReportPublishedMail::class, fn ($mail) => $mail->hasTo('boss@bolt.test'));

        // Later readings never change what the client saw.
        $ig = $this->client->socialAccounts()->firstOrFail();
        $post = $this->client->posts()->where('master_caption', 'September menu')->firstOrFail();
        $this->reading($post, $ig, ['likes' => 5000, 'reach' => 9000]);
        $this->actingAs($this->owner)->post(route('client-reports.refresh', $report))->assertForbidden();
        $this->actingAs($this->owner)->put(route('client-reports.update', $report), ['commentary' => 'late'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('client-reports.publish', $report))->assertForbidden();
        $this->assertSame(100, $report->fresh()?->snapshot['engagement']['totals']['engagement']);
        Mail::assertQueued(ClientReportPublishedMail::class, 1);
    }

    public function test_the_mail_attaches_the_branded_pdf(): void
    {
        $this->seedSeptember();
        $report = $this->prepareSeptember();
        $report->update(['commentary' => 'A strong month.']);

        $mail = new ClientReportPublishedMail($report->fresh(), 'Mira');
        $mail->assertSeeInHtml('A strong month.');
        $mail->assertSeeInHtml('September 2026');
        $mail->assertHasSubject('Your September 2026 social report from '.$this->org->name);
        $this->assertTrue(collect($mail->rawAttachments)->contains(fn ($attachment) => $attachment['options']['mime'] === 'application/pdf'
            && str_starts_with($attachment['data'], '%PDF')));
    }

    public function test_the_portal_shows_only_the_contacts_own_published_reports(): void
    {
        $this->seedSeptember();
        $draft = $this->prepareSeptember();
        $published = ClientReport::query()->create([
            'org_id' => $this->org->id, 'client_id' => $this->client->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'status' => ClientReportStatus::Published, 'published_at' => now(), 'snapshot' => $draft->snapshot,
        ]);
        $foreign = ClientReport::query()->create([
            'org_id' => $this->org->id, 'client_id' => $this->other->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'status' => ClientReportStatus::Published, 'published_at' => now(), 'snapshot' => [],
        ]);

        $this->actingAs($this->reviewer)->get(route('portal.reports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal/reports/index')
                ->has('reports', 1)
                ->where('reports.0.id', $published->id));

        $this->actingAs($this->reviewer)->get(route('portal.reports.show', $published))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.snapshot.posts.published', 1));
        $this->actingAs($this->reviewer)->get(route('portal.reports.pdf', $published))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->reviewer)->get(route('portal.reports.show', $draft))->assertNotFound();
        $this->actingAs($this->reviewer)->get(route('portal.reports.show', $foreign))->assertNotFound();
        $this->actingAs($this->reviewer)->get(route('portal.reports.pdf', $foreign))->assertNotFound();

        // Staff aren't portal contacts.
        $this->actingAs($this->owner)->get(route('portal.reports.index'))->assertForbidden();
    }

    public function test_wrap_reports_shared_that_month_appear_in_it(): void
    {
        $campaign = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id, 'name' => 'Autumn launch']);
        $campaign->report()->create(['summary' => 'Doubled reach.', 'status' => CampaignReportStatus::Shared, 'version' => 1, 'shared_at' => '2026-09-20 10:00:00']);
        $late = Campaign::factory()->for($this->client)->create(['org_id' => $this->org->id, 'name' => 'Winter']);
        $late->report()->create(['summary' => 'Later.', 'status' => CampaignReportStatus::Shared, 'version' => 1, 'shared_at' => '2026-10-03 10:00:00']);

        $campaigns = $this->prepareSeptember()->snapshot['campaigns'];

        $this->assertSame(['Autumn launch'], array_column($campaigns, 'name'));
        $this->assertSame('Doubled reach.', $campaigns[0]['summary']);
    }

    public function test_the_schedule_can_be_changed_by_an_owner(): void
    {
        $this->actingAs($this->owner)->put(route('clients.report-schedule.update', $this->client), ['report_schedule' => 'automatic'])->assertSessionHasNoErrors();
        $this->assertSame(ClientReportSchedule::Automatic, $this->client->fresh()?->report_schedule);

        $this->actingAs($this->owner)->put(route('clients.report-schedule.update', $this->client), ['report_schedule' => 'weekly'])->assertSessionHasErrors('report_schedule');
    }

    public function test_the_monthly_run_drafts_publishes_or_skips_per_schedule_in_the_org_timezone(): void
    {
        $this->seedSeptember();
        $this->travelTo('2026-09-15 10:00:00');
        $automatic = $this->engagementClient($this->org, 'Auto Co');
        $automatic->update(['report_schedule' => ClientReportSchedule::Automatic]);
        User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $automatic->id, 'email' => 'auto@co.test']);
        $off = $this->engagementClient($this->org, 'Quiet Co');
        $off->update(['report_schedule' => ClientReportSchedule::Off]);
        $paused = $this->engagementClient($this->org, 'Paused Co');
        $paused->update(['status' => ClientStatus::Paused]);
        $strategist = $this->member(Role::Strategist);
        $this->member(Role::Designer);

        // 1 Oct 04:00 UTC is 08:00 in Dubai: before the send hour, nothing happens.
        $this->assertSame(['prepared' => 0, 'published' => 0, 'failed' => 0], app(SendMonthlyClientReportsAction::class)(CarbonImmutable::parse('2026-10-01 04:00:00', 'UTC')));

        // 1 Oct 06:00 UTC is 10:00 in Dubai.
        $this->travelTo('2026-10-01 06:00:00');
        $totals = app(SendMonthlyClientReportsAction::class)(CarbonImmutable::now());

        $this->assertSame(['prepared' => 3, 'published' => 1, 'failed' => 0], $totals);
        $reports = ClientReport::query()->withoutOrgScope()->get()->keyBy('client_id');
        $this->assertSame(ClientReportStatus::Draft, $reports[$this->client->id]->status);
        $this->assertSame('2026-09-01', $reports[$this->client->id]->period_start->toDateString());
        $this->assertSame(ClientReportStatus::Published, $reports[$automatic->id]->status);
        $this->assertNull($reports[$automatic->id]->published_by);
        $this->assertArrayNotHasKey($off->id, $reports->all());
        $this->assertArrayNotHasKey($paused->id, $reports->all());
        Mail::assertQueued(ClientReportPublishedMail::class, fn ($mail) => $mail->hasTo('auto@co.test'));
        Mail::assertNotQueued(ClientReportPublishedMail::class, fn ($mail) => $mail->hasTo('marketing@acme.test'));

        // No owner set on the clients: Owners and Strategists hear about the review drafts.
        Notification::assertSentTo([$this->owner, $strategist], ClientReportReady::class);
        Notification::assertCount(2 * 2);

        // Re-running (a later hour, or the next day) never prepares or sends again.
        $sent = Mail::queued(ClientReportPublishedMail::class)->count();
        $this->travelTo('2026-10-02 06:00:00');
        $this->assertSame(['prepared' => 0, 'published' => 0, 'failed' => 0], app(SendMonthlyClientReportsAction::class)(CarbonImmutable::now()));
        $this->assertSame($sent, Mail::queued(ClientReportPublishedMail::class)->count());
    }

    public function test_the_monthly_run_skips_suspended_orgs_and_clients_added_after_the_month(): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        $this->engagementClient($this->org, 'New Co'); // created in October: nothing to report for September
        $suspended = $this->engagementOrg();
        $suspended->forceFill(['suspended_at' => now()])->save();
        $this->travelTo('2026-09-10 12:00:00');
        $this->engagementClient($suspended, 'Hidden');

        $this->travelTo('2026-10-05 12:00:00');
        app(SendMonthlyClientReportsAction::class)(CarbonImmutable::now());

        $names = ClientReport::query()->withoutOrgScope()->with('client')->get()->map(fn ($report) => $report->client?->name)->all();

        $this->assertContains('Acme Cafe', $names);
        $this->assertNotContains('New Co', $names);
        $this->assertNotContains('Hidden', $names);
    }

    public function test_the_studio_pdf_renders(): void
    {
        $this->seedSeptember();
        $report = $this->prepareSeptember();

        $response = $this->actingAs($this->member(Role::Viewer))->get(route('client-reports.pdf', $report));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }
}
