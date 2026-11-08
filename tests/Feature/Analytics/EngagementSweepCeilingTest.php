<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SyncOrganizationEngagementAction;
use App\Contracts\EngagementSource;
use App\Enums\Platform;
use App\Models\Organization;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Audit finding 12 step 2 — can the engagement sweep keep up?
 *
 * Step 1 put `duration_ms` into the job's log, which answers the question in production. Nobody
 * has production traffic yet, so this derives the same answer from the thing the *code* decides:
 * **how many vendor calls one organisation's sweep makes**. That count is deterministic for a
 * given data shape, so it can be measured here and the ceiling computed from it, with the two
 * figures nobody can measure in a test — vendor latency and worker count — named as inputs rather
 * than buried in a guess.
 *
 * The ceiling is the audit's own formula, `orgs × seconds ÷ (processes × 1800)`: above 1 and one
 * sweep has not finished before the next begins, so everything on the `reports` queue — including
 * user-facing exports — queues behind it.
 *
 * What this is **not**: a benchmark of our own SQL. The wall-clock here is SQLite on a developer
 * machine and means nothing. The vendor round trip dominates by two orders of magnitude, and it is
 * the call count that decides how much of it there is.
 */
class EngagementSweepCeilingTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    /**
     * The organisation shape the ceiling is computed for: the audit judged this product at
     * "hundreds to thousands of organisations, each with tens of clients and social accounts".
     */
    private const ACCOUNTS_PER_ORG = 12;

    /**
     * Published channels inside `analytics.engagement.window_days`, deliberately **more** than
     * `max_posts_per_org` so the cap is exercised rather than assumed.
     */
    private const PUBLISHED_IN_WINDOW = 400;

    /**
     * Assumed vendor round trip per read, in seconds. An input, not a measurement: it is the one
     * number that would have to come from production, and every figure below scales linearly with
     * it, so a reader can re-run the arithmetic for their own observed latency.
     */
    private const VENDOR_SECONDS_PER_CALL = 0.25;

    /**
     * `config/horizon.php` → `environments.production.background.maxProcesses`. The `background`
     * supervisor is the one serving `reports`, and it is the smallest in the fleet.
     */
    private const PRODUCTION_PROCESSES = 2;

    /** The sweep's cycle: `analytics:sync-engagement` runs every thirty minutes. */
    private const CYCLE_SECONDS = 1800;

    /**
     * Cycles inside one `min_interval_hours` window. Every account and channel is read once per
     * hour, so this is how many sweeps share the work of one pass — the audit's estimate assumed
     * a full read on every cycle and so doubled the standing load.
     */
    private const CYCLES_PER_INTERVAL = 2;

    private function countingSource(): CountingEngagementSource
    {
        $source = new CountingEngagementSource;
        $this->app->instance(EngagementSource::class, $source);

        return $source;
    }

    /**
     * One organisation at the modelled shape.
     */
    private function seedOrganization(): void
    {
        $org = $this->engagementOrg();
        $accounts = [];

        // Spread over four clients, which is what an agency organisation looks like; the sweep
        // reads per account, so the split matters only in that it exercises the client join.
        for ($i = 0; $i < self::ACCOUNTS_PER_ORG; $i++) {
            $client = $i % 4 === 0
                ? $this->engagementClient($org, 'Client '.$i)
                : $org->clients()->orderByDesc('id')->firstOrFail();

            $accounts[] = $this->engagementAccount($client, Platform::Instagram);
        }

        // One channel per post, published inside the window, so the cap has candidates to choose
        // between and the oldest-read-first ordering has something to order.
        for ($i = 0; $i < self::PUBLISHED_IN_WINDOW; $i++) {
            $this->publishedPost(
                $accounts[$i % count($accounts)]->client,
                [$accounts[$i % count($accounts)]],
                now()->subDays(1 + ($i % 60)),
            );
        }
    }

    public function test_one_organisations_sweep_is_capped_at_the_configured_budget(): void
    {
        config(['analytics.engagement.max_posts_per_org' => 300]);

        $this->seedOrganization();
        $source = $this->countingSource();

        app(SyncOrganizationEngagementAction::class)(Organization::query()->firstOrFail());

        // Exactly the cap, not "about" it: if the limit is ever dropped from the query this fails
        // loudly, because the cost of the sweep is linear in this number and nothing else guards
        // it. 400 channels are in the window; 300 are read and the rest wait for the next run.
        $this->assertCount(300, $source->postCalls, 'The per-org post cap is not being applied.');
        $this->assertCount(self::ACCOUNTS_PER_ORG, $source->accountCalls);
    }

    public function test_the_next_cycle_drains_the_backlog_and_the_one_after_is_free(): void
    {
        config(['analytics.engagement.max_posts_per_org' => 300]);

        $this->seedOrganization();
        $org = Organization::query()->firstOrFail();

        $first = $this->countingSource();
        app(SyncOrganizationEngagementAction::class)($org);
        $this->assertCount(300, $first->postCalls);

        // Not free: the cap left 100 channels unread, and a channel that has never been read is
        // not fresh, so the next cycle picks up exactly the remainder. This is the correction to
        // the first version of this test, which assumed a second sweep was always free — a cycle
        // is only free once the whole window has been caught up.
        $second = $this->countingSource();
        app(SyncOrganizationEngagementAction::class)($org);
        $this->assertCount(self::PUBLISHED_IN_WINDOW - 300, $second->postCalls);
        $this->assertSame([], $second->accountCalls, 'An account was re-read inside min_interval_hours.');

        // Now everything in the window has a reading from inside the interval, so the sweep is a
        // pair of queries that find nothing and stop.
        $third = $this->countingSource();
        app(SyncOrganizationEngagementAction::class)($org);
        $this->assertSame([], $third->postCalls);
    }

    /**
     * The answer step 2 asked for, with the working shown in the failure message.
     *
     * Measured per **hour**, not per cycle, because that is the period the sweep's own guard works
     * in: every account and channel is read once per `min_interval_hours`, however many cycles
     * that takes. Per-cycle framing invites the mistake the audit made.
     */
    public function test_the_sweep_keeps_up_for_at_least_the_scale_it_is_measured_at(): void
    {
        // Pinned, not inherited: a smaller cap would read fewer channels in the two cycles below
        // and quietly understate the hourly cost, which is the one number this test exists for.
        config(['analytics.engagement.max_posts_per_org' => 300]);

        $this->seedOrganization();
        $org = Organization::query()->firstOrFail();

        // Two cycles make one pass over the window at this shape, so the pair is the hourly cost.
        $hour = $this->countingSource();
        app(SyncOrganizationEngagementAction::class)($org);
        app(SyncOrganizationEngagementAction::class)($org);

        $callsPerOrgPerHour = count($hour->postCalls) + count($hour->accountCalls);
        $secondsPerOrgPerHour = $callsPerOrgPerHour * self::VENDOR_SECONDS_PER_CALL;
        $capacityPerHour = self::PRODUCTION_PROCESSES * self::CYCLE_SECONDS * self::CYCLES_PER_INTERVAL;
        $breakEvenOrgs = (int) floor($capacityPerHour / $secondsPerOrgPerHour);

        // 60 is below the measured answer, not an aspiration: it is recorded so that making the
        // sweep more expensive per organisation fails here instead of in production. Raising the
        // real ceiling is a capacity decision — more `background` processes, fewer calls, or a
        // longer interval — and finding 12 carries the options and the measured figure.
        $this->assertGreaterThanOrEqual(
            60,
            $breakEvenOrgs,
            sprintf(
                'The engagement sweep now keeps up for only %d organisations. %d vendor calls per '
                .'org per hour x %.2fs = %.0fs, against %d processes x %ds of capacity.',
                $breakEvenOrgs,
                $callsPerOrgPerHour,
                self::VENDOR_SECONDS_PER_CALL,
                $secondsPerOrgPerHour,
                self::PRODUCTION_PROCESSES,
                $capacityPerHour / self::PRODUCTION_PROCESSES,
            ),
        );
    }

    /**
     * The sweep's own cap against the vendor's documented budget.
     *
     * `config/analytics.php` records the limit in a comment: "Upload-Post's live post endpoint
     * allows 100 requests per 5 minutes per key", and the key is per organisation. Spread across a
     * thirty-minute cycle that is 600 calls, which the cap fits inside — pinned here so raising
     * the cap past it is a deliberate act rather than an accident.
     *
     * It fits the *cycle* budget only. Nothing paces the run inside the cycle, so a worker fires
     * all 312 calls as fast as the vendor answers and crosses the 100-per-5-minutes line within
     * the first minute. See finding 12 for that gap; it is not something this test can assert.
     */
    public function test_the_per_run_cap_fits_the_vendors_documented_budget(): void
    {
        $cap = (int) config('analytics.engagement.max_posts_per_org');
        $vendorCallsPerCycle = 100 * (self::CYCLE_SECONDS / 300);

        $this->assertLessThanOrEqual(
            (int) $vendorCallsPerCycle,
            $cap + self::ACCOUNTS_PER_ORG,
            sprintf(
                'max_posts_per_org (%d) plus the account reads exceeds what one organisation\'s '
                .'vendor key allows in a cycle (%d). Beyond that the reads come back 429, and '
                .'`SyncPostTargetMetricsAction` stamps metrics_synced_at anyway, so those channels '
                .'go stale for an hour without anyone being told.',
                $cap,
                (int) $vendorCallsPerCycle,
            ),
        );
    }
}

/**
 * Always answers with a reading, and counts. `FakeEngagementSource` hands out a queue and returns
 * null once it is empty, which would measure the cheap "nothing to store" path instead of the real
 * one.
 */
class CountingEngagementSource implements EngagementSource
{
    /** @var list<int> */
    public array $postCalls = [];

    /** @var list<int> */
    public array $accountCalls = [];

    public function key(): string
    {
        return 'counting';
    }

    public function postMetrics(PostTarget $target): ?PostMetricsReading
    {
        $this->postCalls[] = $target->id;

        return new PostMetricsReading(reach: 100, likes: 10, comments: 2);
    }

    public function accountMetrics(SocialAccount $account): ?AccountMetricsReading
    {
        $this->accountCalls[] = $account->id;

        return new AccountMetricsReading(followers: 1000, reach: 500);
    }
}
