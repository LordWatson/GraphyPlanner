<?php

namespace Tests\Feature\Analytics;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Analytics\BestTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step M-3.2 / I-10.2 — best time to post per account from seeded readings: slots on the account's
 * own clock, ranked by engagement rate, a learning state below the minimum, and the props that
 * carry it to the editor and the client drill-down.
 */
class BestTimeTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
        config(['analytics.best_time.min_account_posts' => 4, 'analytics.best_time.min_posts' => 2, 'analytics.best_time.block_hours' => 2]);
    }

    /**
     * A measured post on `$account` at a UTC instant.
     */
    private function measured(SocialAccount $account, string $utc, int $engagement, ?int $reach = 1000): Post
    {
        $post = $this->publishedPost($account->client, [$account], CarbonImmutable::parse($utc, 'UTC'));
        $this->reading($post, $account, ['likes' => $engagement, 'reach' => $reach]);

        return $post;
    }

    public function test_slots_rank_by_engagement_rate_on_the_accounts_own_clock(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::Instagram, 'Asia/Dubai');

        // 14:xx UTC = 18:xx in Dubai on Tuesdays (13 and 6 Oct): 5% and 7%.
        $this->measured($account, '2026-10-13 14:10:00', 50);
        $this->measured($account, '2026-10-06 14:40:00', 70);
        // 05:xx UTC = 09:xx Dubai on Thursdays: 2% and 2%.
        $this->measured($account, '2026-10-15 05:00:00', 20);
        $this->measured($account, '2026-10-08 05:30:00', 20);
        // A lone Sunday post can't make a slot on its own (min 2), however good.
        $this->measured($account, '2026-10-11 10:00:00', 900);

        $best = app(BestTimeService::class)->forAccounts([$account->id])[$account->id];

        $this->assertSame('ready', $best['status']);
        $this->assertSame('rate', $best['ranked_by']);
        $this->assertSame(5, $best['sample']);
        $this->assertSame([
            ['weekday' => 2, 'hour' => 18, 'block_hours' => 2, 'label' => 'Tue 18:00–20:00', 'posts' => 2, 'score' => 6.0],
            ['weekday' => 4, 'hour' => 8, 'block_hours' => 2, 'label' => 'Thu 08:00–10:00', 'posts' => 2, 'score' => 2.0],
        ], $best['slots']);
    }

    public function test_without_audience_figures_slots_rank_by_interactions(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::X, 'UTC');

        foreach (['2026-10-13 09:00', '2026-10-06 09:30', '2026-10-14 15:00', '2026-10-07 15:10'] as $index => $at) {
            $this->measured($account, $at, $index < 2 ? 10 : 30, reach: null);
        }

        $best = app(BestTimeService::class)->forAccounts([$account->id])[$account->id];

        $this->assertSame('interactions', $best['ranked_by']);
        $this->assertSame('Wed 14:00–16:00', $best['slots'][0]['label']);
        $this->assertSame(30.0, $best['slots'][0]['score']);
    }

    public function test_an_account_with_too_few_measured_posts_is_still_learning(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $this->measured($account, '2026-10-13 09:00', 10);
        $this->measured($account, '2026-10-06 09:00', 10);
        // Published but never measured: doesn't count.
        $this->publishedPost($account->client, [$account], now()->subDays(3));
        // Measured but too old for the lookback.
        $this->measured($account, '2026-01-01 09:00', 10);
        $this->measured($account, '2026-01-08 09:00', 10);

        $best = app(BestTimeService::class)->forAccounts([$account->id])[$account->id];

        $this->assertSame(['status' => 'learning', 'sample' => 2, 'needed' => 4, 'ranked_by' => null, 'slots' => []], $best);
    }

    public function test_the_editor_and_the_client_drill_down_carry_best_times(): void
    {
        $org = $this->engagementOrg();
        $client = $this->engagementClient($org);
        $account = $this->engagementAccount($client, Platform::Instagram, 'UTC');

        foreach (['2026-10-13 18:00', '2026-10-06 18:30', '2026-10-14 09:00', '2026-10-07 09:15'] as $at) {
            $this->measured($account, $at, 40);
        }

        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $draft = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $this->actingAs($owner)->get(route('posts.edit', $draft))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('targetAccounts.0.best_time.status', 'ready')
                ->where('targetAccounts.0.best_time.slots.0.weekday', 2)
                ->where('targetAccounts.0.best_time.slots.0.hour', 18));

        $this->actingAs($owner)->get(route('clients.analytics', $client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.engagement.best_times.0.social_account_id', $account->id)
                ->where('summary.engagement.best_times.0.status', 'ready'));

        // Org-wide views don't compute it.
        $this->actingAs($owner)->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.engagement.best_times', null));
    }
}
