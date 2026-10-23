<?php

namespace Tests\Unit\Services\Business;

use App\Services\Business\NextSlotService;
use App\Services\Business\PostingGoalGapService;
use App\Support\Workspace\PostingGoal;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Step B-7.2 — the "Next free slot" the quick post flow offers. Pure and timezone-aware.
 */
class NextSlotServiceTest extends TestCase
{
    private function service(): NextSlotService
    {
        return new NextSlotService(new PostingGoalGapService);
    }

    public function test_with_no_goal_it_offers_today_before_the_hour(): void
    {
        // 07:00 local, default hour 9 — today still has its slot.
        $now = Carbon::parse('2026-03-02 07:00', 'Europe/London');

        $slot = $this->service()->next(new PostingGoal, 'Europe/London', $now, [], 9);

        $this->assertSame('2026-03-02', $slot['date']);
        $this->assertSame('09:00', $slot['time']);
    }

    public function test_it_rolls_to_tomorrow_once_the_hour_has_passed(): void
    {
        $now = Carbon::parse('2026-03-02 10:00', 'Europe/London');

        $slot = $this->service()->next(new PostingGoal, 'Europe/London', $now, [], 9);

        $this->assertSame('2026-03-03', $slot['date']);
    }

    public function test_it_respects_posting_goal_days(): void
    {
        // Goal: Fridays only. From a Monday, the next slot is that Friday.
        $goal = new PostingGoal(postsPerWeek: null, preferredDays: [5]);
        $now = Carbon::parse('2026-03-02 08:00', 'Europe/London'); // Monday

        $slot = $this->service()->next($goal, 'Europe/London', $now, [], 9);

        $this->assertSame(5, Carbon::parse($slot['date'])->dayOfWeekIso);
        $this->assertSame('2026-03-06', $slot['date']);
    }

    public function test_it_skips_days_that_already_have_a_post(): void
    {
        $now = Carbon::parse('2026-03-02 10:00', 'Europe/London');

        // Tomorrow is taken, so it jumps to the day after.
        $slot = $this->service()->next(new PostingGoal, 'Europe/London', $now, ['2026-03-03'], 9);

        $this->assertSame('2026-03-04', $slot['date']);
    }

    public function test_the_time_is_computed_in_the_brands_timezone(): void
    {
        // 23:00 UTC is already the next day in Tokyo, so "today" there has rolled over.
        $now = Carbon::parse('2026-03-02 23:00', 'UTC');

        $slot = $this->service()->next(new PostingGoal, 'Asia/Tokyo', $now, [], 9);

        // 08:00 Tokyo on the 3rd is in the future relative to 08:00 Tokyo now (which is the 3rd).
        $this->assertSame('09:00', $slot['time']);
        $this->assertTrue(Carbon::parse($slot['date'])->greaterThanOrEqualTo(Carbon::parse('2026-03-03')));
    }
}
