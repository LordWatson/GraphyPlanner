<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\BuildClientReportSnapshotAction;
use App\Actions\Analytics\GetClientAdsAction;
use App\Actions\Analytics\SyncAdAccountMetricsAction;
use App\Actions\Analytics\SyncOrganizationAdsAction;
use App\Contracts\AdsSource;
use App\Enums\ConnectionStatus;
use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\Ads\AdMetricsReading;
use App\Support\Branding\Branding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase GTM-5 — read-only paid-ads reporting. The source is faked (the real one is behind
 * `AdsSource` so these never touch Meta), and two things are proved throughout: **a gap is a gap**,
 * and **nothing in this module can write to an ad platform**.
 */
class AdsReportingTest extends TestCase
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
            'plan' => 'agency_scale',
            'default_currency' => 'GBP',
        ]);
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Acme Bakery']);
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    /**
     * @param  array<string, array{spend_minor: int|null, impressions: int|null, clicks: int|null, conversions: int|null}>|null  $daily
     */
    private function fakeSource(?array $daily): void
    {
        $this->app->instance(AdsSource::class, new class($daily) implements AdsSource
        {
            /**
             * @param  array<string, array<string, int|null>>|null  $daily
             */
            public function __construct(private ?array $daily) {}

            public function key(): string
            {
                return 'fake';
            }

            public function accountMetrics(AdAccount $account, int $sinceDays): ?AdMetricsReading
            {
                return $this->daily === null ? null : new AdMetricsReading(daily: $this->daily, currency: 'GBP');
            }
        });
    }

    /**
     * @param  array<string, int|null>  $figures
     * @return array<string, array<string, int|null>>
     */
    private function day(string $date, array $figures): array
    {
        return [$date => [
            'spend_minor' => null,
            'impressions' => null,
            'clicks' => null,
            'conversions' => null,
            ...$figures,
        ]];
    }

    private function add(string $externalId = 'act_1234567890'): AdAccount
    {
        $this->actingAs($this->owner)
            ->post(route('clients.ad-accounts.store', $this->client), [
                'platform' => Platform::Facebook->value,
                'external_account_id' => $externalId,
                'name' => 'Acme Meta',
            ])
            ->assertRedirect();

        return AdAccount::query()->withoutOrgScope()->sole();
    }

    public function test_the_contract_exposes_no_way_to_write_to_an_ad_platform(): void
    {
        // Step GTM-5.1 is read-only *by construction*, so this asserts the construction: the
        // contract's whole surface is two reads. A method added here would fail the test before it
        // ever reached a reviewer.
        $methods = array_map(
            fn (\ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(AdsSource::class))->getMethods(),
        );

        sort($methods);
        $this->assertSame(['accountMetrics', 'key'], $methods);
    }

    public function test_a_days_figures_are_one_row_and_a_re_read_restates_them(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->fakeSource($this->day($yesterday, ['spend_minor' => 5000, 'clicks' => 120, 'conversions' => 4]));

        $account = $this->add();
        $this->assertSame(1, $account->metrics()->count());

        // Ad platforms restate recent days as conversions land, which is why the whole window is
        // re-read every night and the upsert has to overwrite.
        $this->fakeSource($this->day($yesterday, ['spend_minor' => 5000, 'clicks' => 120, 'conversions' => 9]));
        $this->assertSame('stored', app(SyncAdAccountMetricsAction::class)($account));

        $this->assertSame(1, $account->metrics()->count());
        $this->assertSame(9, (int) $account->metrics()->sole()->conversions);
    }

    public function test_a_refusing_source_disconnects_the_account_and_writes_no_zeroes(): void
    {
        $this->fakeSource(null);
        $account = $this->add();

        $this->assertSame(0, $account->metrics()->count(), 'No reading means no row.');
        $this->assertFalse($account->refresh()->isConnected());
        $this->assertNotNull($account->metrics_error);

        $payload = app(GetClientAdsAction::class)($this->client);
        $this->assertFalse($payload['accounts'][0]['has_data']);
        $this->assertNull($payload['accounts'][0]['spend_minor'], 'A gap is a dash, never 0 spend.');
        $this->assertNull($payload['totals']['spend_minor']);
    }

    public function test_a_disconnected_account_is_never_read_until_it_is_retried(): void
    {
        $this->fakeSource(null);
        $account = $this->add();
        $this->assertFalse($account->refresh()->isConnected());

        // Still disconnected: the sweep won't touch it, and a direct call refuses.
        $this->assertSame('failed', app(SyncAdAccountMetricsAction::class)($account));
        $this->assertSame(0, app(SyncOrganizationAdsAction::class)($this->org)['stored']);

        // "Refresh" re-connects first, so a fixed permission can be picked up.
        $this->fakeSource($this->day(now()->subDay()->toDateString(), ['spend_minor' => 1000]));
        $this->actingAs($this->owner)
            ->post(route('clients.ad-accounts.sync', ['client' => $this->client, 'adAccount' => $account]))
            ->assertRedirect();

        $this->assertTrue($account->refresh()->isConnected());
        $this->assertSame(1, $account->metrics()->count());
    }

    public function test_cost_per_result_is_derived_and_unanswerable_without_a_denominator(): void
    {
        $this->fakeSource($this->day(now()->subDay()->toDateString(), [
            'spend_minor' => 9000,
            'clicks' => 300,
            'conversions' => 0,
        ]));

        $this->add();
        $row = app(GetClientAdsAction::class)($this->client)['accounts'][0];

        $this->assertSame(30, $row['cpc_minor'], '9000 over 300 clicks.');
        $this->assertNull($row['cpa_minor'], 'Cost per result with no results is unanswerable, not infinite.');
    }

    public function test_a_plan_without_ads_reporting_sees_nothing_and_is_never_swept(): void
    {
        $this->fakeSource($this->day(now()->subDay()->toDateString(), ['spend_minor' => 1000]));
        $this->add();

        $this->org->forceFill(['plan' => 'business_pro', 'type' => OrganizationType::Business])->save();

        $payload = app(GetClientAdsAction::class)($this->client);
        $this->assertFalse($payload['entitled']);
        $this->assertSame([], $payload['accounts']);

        $this->assertSame('not_entitled', app(SyncOrganizationAdsAction::class)($this->org)['skipped']);

        // And it cannot add another one.
        $this->actingAs($this->owner)->post(route('clients.ad-accounts.store', $this->client), [
            'platform' => Platform::Facebook->value,
            'external_account_id' => 'act_999',
        ]);
        $this->assertSame(1, AdAccount::query()->withoutOrgScope()->count());
    }

    public function test_only_meta_ad_accounts_are_accepted_and_ids_are_normalised(): void
    {
        $this->fakeSource(null);

        $this->actingAs($this->owner)
            ->post(route('clients.ad-accounts.store', $this->client), [
                'platform' => Platform::TikTok->value,
                'external_account_id' => 'act_123',
            ])
            ->assertSessionHasErrors('platform');

        $this->actingAs($this->owner)
            ->post(route('clients.ad-accounts.store', $this->client), [
                'platform' => Platform::Facebook->value,
                'external_account_id' => 'not-an-id',
            ])
            ->assertSessionHasErrors('external_account_id');

        $account = $this->add('act_1234567890');
        $this->assertSame('1234567890', $account->external_account_id);

        // The same account can't be reported on twice, prefix or not.
        $this->actingAs($this->owner)
            ->post(route('clients.ad-accounts.store', $this->client), [
                'platform' => Platform::Facebook->value,
                'external_account_id' => '1234567890',
            ])
            ->assertSessionHasErrors('external_account_id');
    }

    public function test_the_command_queues_only_organizations_with_ad_accounts(): void
    {
        $this->fakeSource(null);
        $this->add();

        Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_scale']);

        $this->artisan('ads:sync')->expectsOutputToContain('1 organization(s)')->assertSuccessful();
    }

    public function test_the_panel_and_the_report_show_paid_beside_organic(): void
    {
        $this->fakeSource($this->day(now()->subDay()->toDateString(), [
            'spend_minor' => 12345,
            'clicks' => 500,
            'conversions' => 25,
        ]));
        $this->add();

        $payload = app(GetClientAdsAction::class)($this->client);
        $this->assertTrue($payload['entitled']);
        $this->assertSame(12345, $payload['totals']['spend_minor']);
        $this->assertSame(494, $payload['totals']['cpa_minor']);
        $this->assertSame(0, $payload['organic']['posts']);
        $this->assertNull($payload['organic']['engagement'], 'Nothing measured is not zero interactions.');

        $snapshot = app(BuildClientReportSnapshotAction::class)($this->client, CarbonImmutable::now()->startOfMonth());
        $this->assertNotNull($snapshot['ads']);
        $this->assertSame(12345, $snapshot['ads']['totals']['spend_minor']);

        $html = view('pdf.client-report', [
            'report' => new ClientReport(['client_id' => $this->client->id, 'org_id' => $this->org->id]),
            'snapshot' => $snapshot,
            'branding' => Branding::alima(),
            'brandLogo' => null,
        ])->render();

        $this->assertStringContainsString('Paid and organic', $html);
        $this->assertStringContainsString('123.45', $html);
    }

    public function test_the_report_block_is_absent_when_there_is_nothing_to_report(): void
    {
        $snapshot = app(BuildClientReportSnapshotAction::class)($this->client, CarbonImmutable::now()->startOfMonth());

        $this->assertNull($snapshot['ads'], 'No ad accounts means no section at all.');
    }

    public function test_another_organizations_ad_account_is_untouchable(): void
    {
        $this->fakeSource(null);
        $mine = $this->add();

        $otherOrg = Organization::factory()->create(['type' => OrganizationType::Agency, 'plan' => 'agency_scale']);
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $this->app->forgetScopedInstances();
        $this->actingAs($otherOwner)
            ->delete(route('clients.ad-accounts.destroy', ['client' => $otherClient, 'adAccount' => $mine]))
            ->assertNotFound();

        $this->assertDatabaseHas('ad_accounts', ['id' => $mine->id]);
    }

    public function test_the_default_binding_reports_nothing_rather_than_guessing(): void
    {
        // `ads.provider` is unset in tests, exactly as in a fresh install: an agency sees honest
        // gaps until Meta grants `ads_read`, never invented figures.
        config(['ads.provider' => null]);
        $this->app->forgetInstance(AdsSource::class);

        $account = AdAccount::create([
            'org_id' => $this->org->id,
            'client_id' => $this->client->id,
            'platform' => Platform::Facebook,
            'external_account_id' => '1234567890',
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $this->assertSame('null', app(AdsSource::class)->key());
        $this->assertSame('failed', app(SyncAdAccountMetricsAction::class)($account));
        $this->assertSame(0, $account->metrics()->count());
    }
}
