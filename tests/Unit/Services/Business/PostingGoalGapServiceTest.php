<?php

namespace Tests\Unit\Services\Business;

use App\Services\Business\PostingGoalGapService;
use App\Support\Workspace\PostingGoal;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Step B-4.5 — the "Gaps" computation: days in the window the brand means to post on but hasn't.
 * Tested across a week boundary and a DST change, both resolved in the brand's own timezone.
 */
class PostingGoalGapServiceTest extends TestCase
{
    private PostingGoalGapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PostingGoalGapService;
    }

    public function test_no_goal_yields_no_gaps(): void
    {
        $result = $this->service->gaps(new PostingGoal, 'UTC', Carbon::parse('2026-10-22'), 14, []);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['days']);
    }

    public function test_preferred_days_flag_empty_days_only(): void
    {
        // Monday 2026-10-19; goal = Mon/Wed/Fri. Over 14 days that's 6 target days.
        $goal = new PostingGoal(null, [1, 3, 5]);
        $now = Carbon::parse('2026-10-19 08:00:00', 'Europe/London');

        // Wednesday 21st already has a post — it should drop out of the gaps.
        $result = $this->service->gaps($goal, 'Europe/London', $now, 14, ['2026-10-21']);

        $dates = array_column($result['days'], 'date');
        $this->assertContains('2026-10-19', $dates);
        $this->assertContains('2026-10-23', $dates);
        $this->assertNotContains('2026-10-21', $dates);
        // Only Mon/Wed/Fri appear — never a Tuesday.
        $this->assertNotContains('2026-10-20', $dates);
        $this->assertSame(5, $result['count']);
    }

    public function test_gaps_span_a_week_boundary(): void
    {
        // Goal includes Sunday (7) and Monday (1). Start on Saturday so the window crosses Sun→Mon.
        $goal = new PostingGoal(null, [7, 1]);
        $now = Carbon::parse('2026-10-24 10:00:00', 'Europe/London'); // Saturday

        $result = $this->service->gaps($goal, 'Europe/London', $now, 7, []);
        $dates = array_column($result['days'], 'date');

        // Sun 25th and Mon 26th are both within 7 days and both empty.
        $this->assertContains('2026-10-25', $dates);
        $this->assertContains('2026-10-26', $dates);
    }

    public function test_today_is_counted_in_the_brand_timezone(): void
    {
        // 23:30 UTC on the 22nd is already the 23rd in Sydney. A goal day "today" (Sydney) with a
        // post should not be a gap, and the window starts from the Sydney day, not the UTC instant.
        $goal = new PostingGoal(null, [1, 2, 3, 4, 5, 6, 7]);
        $now = Carbon::parse('2026-10-22 23:30:00', 'UTC');

        $result = $this->service->gaps($goal, 'Australia/Sydney', $now, 3, ['2026-10-23']);
        $dates = array_column($result['days'], 'date');

        $this->assertNotContains('2026-10-23', $dates);
        // The following two Sydney days are still gaps.
        $this->assertContains('2026-10-24', $dates);
        $this->assertContains('2026-10-25', $dates);
    }

    public function test_dst_change_does_not_shift_local_days(): void
    {
        // UK clocks go back on Sunday 2026-10-25. Days are still bucketed by local date.
        $goal = new PostingGoal(null, [6, 7, 1]); // Sat, Sun, Mon around the change
        $now = Carbon::parse('2026-10-24 09:00:00', 'Europe/London');

        $result = $this->service->gaps($goal, 'Europe/London', $now, 5, ['2026-10-25']);
        $dates = array_column($result['days'], 'date');

        $this->assertContains('2026-10-24', $dates); // Sat
        $this->assertNotContains('2026-10-25', $dates); // Sun — covered
        $this->assertContains('2026-10-26', $dates); // Mon
    }

    public function test_posts_per_week_without_preferred_days_spreads_across_the_week(): void
    {
        $goal = new PostingGoal(3, []);

        $this->assertSame([1, 3, 5], $this->service->effectiveDays($goal));
    }

    public function test_a_daily_goal_targets_every_day(): void
    {
        $goal = new PostingGoal(7, []);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->service->effectiveDays($goal));
    }
}
