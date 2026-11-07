<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\BuildClientReportSnapshotAction;
use App\Actions\Benchmarks\GetClientBenchmarksAction;
use App\Actions\Benchmarks\SyncOrganizationBenchmarksAction;
use App\Actions\Benchmarks\SyncTrackedProfileAction;
use App\Contracts\CompetitorProfileSource;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\TrackedProfile;
use App\Models\TrackedProfileMetric;
use App\Models\User;
use App\Support\Analytics\Benchmarks\CompetitorProfileReading;
use App\Support\Branding\Branding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase GTM-4 — competitor benchmarking. The source is faked (the real one is behind
 * `CompetitorProfileSource` so these never touch Meta), and the thing proved throughout is that
 * **a gap stays a gap**: no row, no zero, and no invented figure anywhere between the source and
 * the page.
 */
class BenchmarksTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'type' => OrganizationType::Agency,
            'plan' => 'agency_standard',
            'default_timezone' => 'Europe/London',
        ]);
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme Bakery']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    /**
     * @param  array<string, CompetitorProfileReading|null>  $byHandle
     */
    private function fakeSource(array $byHandle): void
    {
        $this->app->instance(CompetitorProfileSource::class, new class($byHandle) implements CompetitorProfileSource
        {
            /**
             * @param  array<string, CompetitorProfileReading|null>  $byHandle
             */
            public function __construct(private array $byHandle) {}

            public function key(): string
            {
                return 'fake';
            }

            public function profile(TrackedProfile $profile): ?CompetitorProfileReading
            {
                return $this->byHandle[$profile->handle] ?? null;
            }
        });
    }

    private function track(string $handle, ?string $label = null): TrackedProfile
    {
        $this->actingAs($this->owner)
            ->post(route('clients.benchmarks.store', $this->client), [
                'platform' => Platform::Instagram->value,
                'handle' => $handle,
                'label' => $label,
            ])
            ->assertRedirect();

        return TrackedProfile::query()
            ->withoutOrgScope()
            ->where('handle', TrackedProfile::normaliseHandle($handle))
            ->sole();
    }

    public function test_a_reading_is_one_row_a_day_and_a_second_run_overwrites_it(): void
    {
        $this->fakeSource(['rival' => new CompetitorProfileReading(followers: 1200, postCount: 340, avgEngagement: 88)]);
        $profile = $this->track('@Rival');

        // Normalised on the way in, so `@Rival` and `rival` can't both be tracked.
        $this->assertSame('rival', $profile->handle);
        $this->assertSame(1, $profile->metrics()->count());

        $this->fakeSource(['rival' => new CompetitorProfileReading(followers: 1250, postCount: 341, avgEngagement: 90)]);
        $this->assertSame('stored', app(SyncTrackedProfileAction::class)($profile));

        $this->assertSame(1, $profile->metrics()->count(), 'A second read on the same day overwrites.');
        $this->assertSame(1250, (int) $profile->metrics()->sole()->followers);
    }

    public function test_a_refusing_source_leaves_a_gap_and_never_a_zero(): void
    {
        $this->fakeSource(['rival' => null]);
        $profile = $this->track('rival');

        $this->assertSame(0, $profile->metrics()->count(), 'No reading means no row at all.');
        $this->assertNotNull($profile->refresh()->last_error);
        $this->assertNotNull($profile->last_synced_at);

        // And a reading where the platform reported only some fields stores only those.
        $this->fakeSource(['rival' => new CompetitorProfileReading(followers: 900)]);
        $this->assertSame('stored', app(SyncTrackedProfileAction::class)($profile));

        $row = $profile->metrics()->sole();
        $this->assertSame(900, (int) $row->followers);
        $this->assertNull($row->post_count, 'An unreported figure stays null, not 0.');
        $this->assertNull($row->avg_engagement);
    }

    public function test_the_per_plan_limit_is_enforced_across_the_whole_organization(): void
    {
        // agency_starter allows 25; drop it to 1 so the cap is reachable in a test.
        config(['plans.plans.agency_standard.limits.tracked_profiles' => 1]);
        $this->fakeSource([]);

        $this->track('first');

        // A second brand in the same org shares the allowance, because each profile is a nightly
        // read against the same rate limit whichever brand it hangs off.
        $other = Client::factory()->for($this->org, 'organization')->create();

        $this->actingAs($this->owner)->post(route('clients.benchmarks.store', $other), [
            'platform' => Platform::Instagram->value,
            'handle' => 'second',
        ]);

        $this->assertSame(1, TrackedProfile::query()->withoutOrgScope()->count());
    }

    public function test_a_plan_without_benchmarks_can_neither_track_nor_see_them(): void
    {
        $this->fakeSource([]);
        $this->org->forceFill(['plan' => 'business_free', 'type' => OrganizationType::Business])->save();

        $this->actingAs($this->owner)->post(route('clients.benchmarks.store', $this->client), [
            'platform' => Platform::Instagram->value,
            'handle' => 'rival',
        ]);

        $this->assertSame(0, TrackedProfile::query()->withoutOrgScope()->count());

        $payload = app(GetClientBenchmarksAction::class)($this->client);
        $this->assertFalse($payload['entitled']);
        $this->assertSame([], $payload['profiles']);
    }

    public function test_a_platform_we_cannot_legitimately_read_is_refused_outright(): void
    {
        $this->fakeSource([]);

        $this->actingAs($this->owner)
            ->post(route('clients.benchmarks.store', $this->client), [
                'platform' => Platform::TikTok->value,
                'handle' => 'rival',
            ])
            ->assertSessionHasErrors('platform');

        $this->assertSame(0, TrackedProfile::query()->withoutOrgScope()->count());
    }

    public function test_the_nightly_sweep_skips_profiles_already_read_today_and_a_lost_plan(): void
    {
        $this->fakeSource([
            'rival' => new CompetitorProfileReading(followers: 100),
            'other' => new CompetitorProfileReading(followers: 200),
        ]);

        $read = $this->track('rival');
        $waiting = $this->track('other');
        // Pretend `other` has never been read.
        $waiting->forceFill(['last_synced_at' => null])->save();
        $read->forceFill(['last_synced_at' => now()])->save();

        $summary = app(SyncOrganizationBenchmarksAction::class)($this->org);
        $this->assertSame(1, $summary['stored'] + $summary['gap'], 'Only the unread profile is swept.');

        // A downgrade stops the reads, not just the panel.
        $this->org->forceFill(['plan' => 'business_free', 'type' => OrganizationType::Business])->save();
        $this->assertSame('not_entitled', app(SyncOrganizationBenchmarksAction::class)($this->org, force: true)['skipped']);
    }

    public function test_the_command_queues_only_organizations_with_tracked_profiles(): void
    {
        $this->fakeSource(['rival' => new CompetitorProfileReading(followers: 10)]);
        $this->track('rival');

        Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_standard']);

        $this->artisan('benchmarks:sync')
            ->expectsOutputToContain('1 organization(s)')
            ->assertSuccessful();
    }

    public function test_the_panel_payload_shows_gaps_as_gaps_and_a_window_with_no_readings_as_empty(): void
    {
        $this->fakeSource([]);
        $tracked = $this->track('rival', 'The Rival');
        $this->assertSame(0, $tracked->metrics()->count());

        $payload = app(GetClientBenchmarksAction::class)($this->client);
        $this->assertTrue($payload['entitled']);
        $this->assertCount(1, $payload['profiles']);
        $this->assertFalse($payload['profiles'][0]['has_data']);
        $this->assertNull($payload['profiles'][0]['followers']);
        $this->assertNull($payload['profiles'][0]['avg_engagement']);
        $this->assertSame('The Rival', $payload['profiles'][0]['name']);

        // A reading outside the window is outside the window: still a gap, not a stale figure.
        TrackedProfileMetric::create([
            'org_id' => $this->org->id,
            'tracked_profile_id' => $tracked->id,
            'captured_on' => now()->subDays((int) config('benchmarks.window_days', 30) + 5)->toDateString(),
            'followers' => 5000,
            'fetched_at' => now(),
        ]);

        $this->assertFalse(app(GetClientBenchmarksAction::class)($this->client)['profiles'][0]['has_data']);
    }

    public function test_the_panel_shows_the_follower_change_across_the_window(): void
    {
        $this->fakeSource([]);
        $tracked = $this->track('rival');

        foreach ([[10, 1000], [2, 1180]] as [$daysAgo, $followers]) {
            TrackedProfileMetric::create([
                'org_id' => $this->org->id,
                'tracked_profile_id' => $tracked->id,
                'captured_on' => now()->subDays($daysAgo)->toDateString(),
                'followers' => $followers,
                'post_count' => 300,
                'avg_engagement' => 42,
                'fetched_at' => now(),
            ]);
        }

        $row = app(GetClientBenchmarksAction::class)($this->client)['profiles'][0];

        $this->assertTrue($row['has_data']);
        $this->assertSame(1180, $row['followers'], 'The newest reading in the window.');
        $this->assertSame(180, $row['followers_change']);
        $this->assertSame(42, $row['avg_engagement']);
    }

    public function test_the_client_analytics_page_carries_the_panel_and_only_instagram_is_offered(): void
    {
        $this->fakeSource([]);
        $this->track('rival');

        $this->actingAs($this->owner)
            ->get(route('clients.analytics', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/client')
                ->where('benchmarks.entitled', true)
                ->has('benchmarks.profiles', 1)
                ->has('benchmarkPlatforms', 1)
                ->where('benchmarkPlatforms.0.value', Platform::Instagram->value)
                ->where('canManageBenchmarks', true));
    }

    public function test_the_report_snapshot_carries_the_block_only_when_there_is_something_to_show(): void
    {
        $this->fakeSource(['rival' => new CompetitorProfileReading(followers: 1200)]);

        // Nothing tracked: no block at all, so the PDF and the portal page skip the section.
        $bare = app(BuildClientReportSnapshotAction::class)(
            $this->client,
            CarbonImmutable::now()->startOfMonth(),
        );
        $this->assertNull($bare['benchmarks']);

        $this->track('rival');

        $withBlock = app(BuildClientReportSnapshotAction::class)(
            $this->client,
            CarbonImmutable::now()->startOfMonth(),
        );
        $this->assertNotNull($withBlock['benchmarks']);
        $this->assertSame(1200, $withBlock['benchmarks']['profiles'][0]['followers']);

        // The PDF renders the block, and an unreported figure is a dash.
        $html = view('pdf.client-report', [
            'report' => new ClientReport(['client_id' => $this->client->id, 'org_id' => $this->org->id]),
            'snapshot' => $withBlock,
            'branding' => Branding::alima(),
            'brandLogo' => null,
        ])->render();

        $this->assertStringContainsString('How you compare', $html);
        $this->assertStringContainsString('1,200', $html);
    }

    public function test_another_organizations_profile_is_never_visible_or_touchable(): void
    {
        $this->fakeSource([]);
        $mine = $this->track('rival');

        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_standard']);
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $this->actingAs($otherOwner)
            ->delete(route('clients.benchmarks.destroy', ['client' => $otherClient, 'profile' => $mine]))
            ->assertNotFound();
        $this->assertDatabaseHas('tracked_profiles', ['id' => $mine->id]);

        // The org scope keeps it out of every list query too.
        $this->actingAs($otherOwner);
        $this->assertSame(0, TrackedProfile::query()->count());
        $this->assertSame(0, DB::table('tracked_profiles')->where('org_id', $otherOrg->id)->count());
    }
}
