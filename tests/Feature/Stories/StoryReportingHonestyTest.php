<?php

namespace Tests\Feature\Stories;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Actions\Posts\GetPublishReliabilityStatsAction;
use App\Actions\Stories\ExpireStoryHandoffAction;
use App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\Role;
use App\Enums\StoryHandoffOutcome;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\StoryHandoff;
use App\Models\User;
use App\Services\Analytics\EngagementMetrics;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step S-5.3 — reporting honesty for the assisted lane: a Story nobody posted by hand is counted
 * apart from publishing failures, and a Story that was posted by hand reads "not available" in
 * analytics instead of pretending to zero engagement.
 */
class StoryReportingHonestyTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    private Organization $org;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
        config(['publishing.story_handoff.enabled' => true]);

        $this->org = $this->engagementOrg();
        $this->client = $this->engagementClient($this->org, 'Acme');
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    // --- Publish reliability ------------------------------------------------------------------

    /**
     * A post whose Story channel was missed: the handoff closed as `missed` and the post's failure
     * transition was written at the same moment, as `ExpireStoryHandoffAction` does.
     *
     * @return array{0: Post, 1: PostTarget}
     */
    private function missedStory(PostStatus $resolvedTo = PostStatus::Failed): array
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => $resolvedTo]);
        $account = $this->engagementAccount($this->client, Platform::Instagram);

        /** @var PostTarget $target */
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'format' => 'story',
            'status' => PostTargetStatus::Failed,
            'error' => ExpireStoryHandoffAction::MISSED_ERROR,
            'publish_attempt_id' => 'attempt-1',
        ]);
        $target->forceFill(['handoff_required' => true])->save();

        StoryHandoff::factory()->missed()->create(['post_target_id' => $target->id]);
        PostActivityLog::factory()->for($post)->create(['to_status' => $resolvedTo, 'created_at' => now()]);

        return [$post, $target];
    }

    private function vendorFailure(Post $post, ?SocialAccount $account = null): void
    {
        $post->targets()->create([
            'social_account_id' => ($account ?? $this->engagementAccount($this->client, Platform::Facebook))->id,
            'status' => PostTargetStatus::Failed,
            'error' => 'Rate limit exceeded',
        ]);
    }

    public function test_a_missed_story_is_counted_apart_from_publishing_failures(): void
    {
        $this->missedStory();
        $this->missedStory(PostStatus::PartiallyPublished);

        // An ordinary vendor failure and a publish, on other posts.
        $failed = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Failed]);
        $this->vendorFailure($failed);
        PostActivityLog::factory()->for($failed)->create(['to_status' => PostStatus::Failed, 'created_at' => now()]);
        $published = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Published]);
        PostActivityLog::factory()->for($published)->create(['to_status' => PostStatus::Published, 'created_at' => now()]);

        $stats = app(GetPublishReliabilityStatsAction::class)($this->org->id);

        $this->assertSame(2, $stats['missedByHand']);
        $this->assertSame(2, $stats['monthly'][5]['missed_by_hand']);
        $this->assertSame(1, $stats['monthly'][5]['failed']);
        $this->assertSame(1, $stats['monthly'][5]['published']);
        $this->assertSame(0, $stats['monthly'][4]['missed_by_hand']);
    }

    public function test_a_missed_story_is_not_an_attempt_before_success(): void
    {
        [$post] = $this->missedStory();
        // Retried, posted by hand this time.
        PostActivityLog::factory()->for($post)->create(['to_status' => PostStatus::Published, 'created_at' => now()->addHour()]);
        $post->targets()->update(['status' => PostTargetStatus::Published, 'error' => null]);

        $stats = app(GetPublishReliabilityStatsAction::class)($this->org->id);

        $this->assertSame(1.0, $stats['averageAttemptsToSuccess']);
        $this->assertSame(1, $stats['missedByHand']);
        $this->assertSame(0, $stats['monthly'][5]['failed']);
    }

    public function test_a_real_vendor_failure_beside_a_missed_story_still_counts_as_failed(): void
    {
        [$post] = $this->missedStory();
        $this->vendorFailure($post);

        $stats = app(GetPublishReliabilityStatsAction::class)($this->org->id);

        $this->assertSame(0, $stats['missedByHand']);
        $this->assertSame(1, $stats['monthly'][5]['failed']);
    }

    public function test_an_earlier_vendor_failure_on_the_same_post_is_still_a_failure(): void
    {
        [$post] = $this->missedStory();
        // The day before, the same post failed at the vendor and was retried.
        PostActivityLog::factory()->for($post)->create(['to_status' => PostStatus::Failed, 'created_at' => now()->subDay()]);

        $stats = app(GetPublishReliabilityStatsAction::class)($this->org->id);

        $this->assertSame(1, $stats['missedByHand']);
        $this->assertSame(1, $stats['monthly'][5]['failed']);
    }

    public function test_another_organizations_missed_stories_are_not_counted(): void
    {
        $this->missedStory();
        $other = Organization::factory()->create();

        $stats = app(GetPublishReliabilityStatsAction::class)($other->id);

        $this->assertSame(0, $stats['missedByHand']);
        $this->assertSame(0, array_sum(array_column($stats['monthly'], 'missed_by_hand')));
    }

    public function test_the_dashboard_shares_the_missed_figure(): void
    {
        $this->missedStory();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('publishReliability.missedByHand', 1)
                ->where('publishReliability.monthly.5.missed_by_hand', 1)
                ->where('publishReliability.monthly.5.failed', 0));
    }

    // --- Analytics ----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function engagement(): array
    {
        return app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray([], $this->org),
            $this->owner,
        )['engagement'];
    }

    /**
     * @param  array<string, mixed>  $engagement
     * @return array<string, mixed>
     */
    private function platformRow(array $engagement, Platform $platform): array
    {
        foreach ($engagement['by_platform'] as $row) {
            if ($row['platform'] === $platform->value) {
                return $row;
            }
        }

        $this->fail("No {$platform->value} row.");
    }

    public function test_a_story_posted_by_hand_reads_not_available_instead_of_zero(): void
    {
        $ig = $this->engagementAccount($this->client, Platform::Instagram);
        $feed = $this->publishedPost($this->client, [$ig], now()->subDays(3));
        $this->reading($feed, $ig, ['likes' => 40, 'reach' => 1000]);

        $story = $this->publishedPost($this->client, [$ig], now()->subDays(2));
        $story->targets()->firstOrFail()->forceFill(['format' => 'story', 'handoff_required' => true])->save();

        $engagement = $this->engagement();

        $this->assertSame(2, $engagement['published_channels']);
        $this->assertSame(1, $engagement['measured_channels']);
        $this->assertSame(1, $engagement['posted_by_hand_channels']);
        // The Story adds nothing to the totals or the rate: 40 ÷ 1000, not 40 ÷ 1000 over two posts.
        $this->assertSame(40, $engagement['totals']['engagement']);
        $this->assertSame(4.0, $engagement['engagement_rate']);

        $row = $this->platformRow($engagement, Platform::Instagram);
        $this->assertTrue($row['available']);
        $this->assertSame(2, $row['published']);
        $this->assertSame(1, $row['measured']);
        $this->assertSame(1, $row['posted_by_hand']);
        $this->assertSame(EngagementMetrics::POSTED_BY_HAND_NOTE, $row['posted_by_hand_note']);

        $this->assertSame([$feed->id], array_column($engagement['top_posts'], 'post_id'));
    }

    public function test_a_stray_reading_on_a_story_posted_by_hand_is_never_counted(): void
    {
        $ig = $this->engagementAccount($this->client, Platform::Instagram);
        $story = $this->publishedPost($this->client, [$ig], now()->subDays(2));
        $this->reading($story, $ig, ['likes' => 0, 'reach' => 0]);
        $story->targets()->firstOrFail()->forceFill(['format' => 'story', 'handoff_required' => true])->save();

        $engagement = $this->engagement();

        $this->assertSame(0, $engagement['measured_channels']);
        $this->assertSame(1, $engagement['posted_by_hand_channels']);
        $this->assertNull($engagement['totals']['engagement']);
        $this->assertNull($engagement['engagement_rate']);
        $this->assertSame([], $engagement['top_posts']);
    }

    // --- Super-admin publishing view -----------------------------------------------------------

    private function closedHandoff(StoryHandoffOutcome $outcome, ?int $minutesToPost, ?string $completedAt = null, ?Client $client = null): StoryHandoff
    {
        $client ??= $this->client;
        $completed = Carbon::parse($completedAt ?? now()->subDay());
        $post = Post::factory()->for($client)->create(['org_id' => $client->org_id, 'status' => PostStatus::Published]);
        $target = $post->targets()->create([
            'social_account_id' => $this->engagementAccount($client, Platform::Instagram)->id,
            'format' => 'story',
        ]);

        return StoryHandoff::factory()->create([
            'post_target_id' => $target->id,
            'outcome' => $outcome,
            'opened_at' => $minutesToPost === null ? null : $completed->copy()->subMinutes($minutesToPost),
            'completed_at' => $completed,
        ]);
    }

    public function test_the_platform_summary_reports_how_the_lane_is_doing(): void
    {
        $this->closedHandoff(StoryHandoffOutcome::Posted, 2);
        $this->closedHandoff(StoryHandoffOutcome::Posted, 4);
        $this->closedHandoff(StoryHandoffOutcome::Posted, 10);
        $this->closedHandoff(StoryHandoffOutcome::Posted, 7);
        // Marked as posted from the editor: never opened, so it has no time to post.
        $this->closedHandoff(StoryHandoffOutcome::Posted, null);
        $this->closedHandoff(StoryHandoffOutcome::Missed, null);
        $this->closedHandoff(StoryHandoffOutcome::Cancelled, 1);
        // Outside the 30-day range, and the preview fixture: neither counts.
        $this->closedHandoff(StoryHandoffOutcome::Posted, 500, '2026-08-01 10:00:00');
        $preview = Organization::factory()->create(['slug' => Organization::PREVIEW_SLUG]);
        $this->closedHandoff(StoryHandoffOutcome::Posted, 900, client: $this->engagementClient($preview, 'Demo'));

        $posts = app(GetPlatformAnalyticsSummaryAction::class)(AnalyticsFilters::fromArray([]))['posts'];

        $this->assertSame(5, $posts['stories_posted_by_hand']);
        $this->assertSame(1, $posts['stories_missed']);
        // Median of 2, 4, 7 and 10 minutes.
        $this->assertSame(5.5, $posts['stories_median_minutes_to_post']);
    }

    public function test_the_platform_summary_has_no_median_before_anyone_posts(): void
    {
        $posts = app(GetPlatformAnalyticsSummaryAction::class)(AnalyticsFilters::fromArray([]))['posts'];

        $this->assertSame(0, $posts['stories_posted_by_hand']);
        $this->assertSame(0, $posts['stories_missed']);
        $this->assertNull($posts['stories_median_minutes_to_post']);
    }

    public function test_an_auto_published_story_is_measured_as_before(): void
    {
        $ig = $this->engagementAccount($this->client, Platform::Instagram);
        $story = $this->publishedPost($this->client, [$ig], now()->subDays(2));
        $story->targets()->firstOrFail()->forceFill(['format' => 'story'])->save();
        $this->reading($story, $ig, ['likes' => 5, 'reach' => 100]);

        $engagement = $this->engagement();

        $this->assertSame(1, $engagement['measured_channels']);
        $this->assertSame(0, $engagement['posted_by_hand_channels']);
        $row = $this->platformRow($engagement, Platform::Instagram);
        $this->assertSame(0, $row['posted_by_hand']);
        $this->assertNull($row['posted_by_hand_note']);
    }
}
