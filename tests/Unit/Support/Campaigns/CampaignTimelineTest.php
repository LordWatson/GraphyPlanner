<?php

namespace Tests\Unit\Support\Campaigns;

use App\Models\Campaign;
use App\Support\Campaigns\CampaignTimeline;
use App\Support\Campaigns\DeliverableDueDate;
use App\Support\Campaigns\SuggestedPhases;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CampaignTimelineTest extends TestCase
{
    private function campaign(?string $start, ?string $end): Campaign
    {
        return new Campaign(['start_date' => $start, 'end_date' => $end]);
    }

    public function test_an_undated_campaign_is_undated(): void
    {
        $timeline = CampaignTimeline::for($this->campaign(null, null), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame(CampaignTimeline::UNDATED, $timeline->state);
        $this->assertSame('No dates yet.', $timeline->sentence());
    }

    public function test_an_upcoming_campaign_counts_days_to_launch(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-10-24', '2026-11-30'), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame(CampaignTimeline::UPCOMING, $timeline->state);
        $this->assertSame(6, $timeline->daysToLaunch);
        $this->assertSame('Launches in 6 days.', $timeline->sentence());
    }

    public function test_tomorrow_reads_naturally(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-10-19', null), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame('Launches tomorrow.', $timeline->sentence());
    }

    public function test_a_live_campaign_counts_days_left_including_its_start_day(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-10-18', '2026-10-30'), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame(CampaignTimeline::LIVE, $timeline->state);
        $this->assertSame(12, $timeline->daysLeft);
        $this->assertSame('Live. 12 days left.', $timeline->sentence());
    }

    public function test_the_last_day_is_still_live(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-10-01', '2026-10-18'), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame(CampaignTimeline::LIVE, $timeline->state);
        $this->assertSame('Live. Last day today.', $timeline->sentence());
    }

    public function test_an_open_ended_campaign_stays_live(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-09-01', null), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame('Live. No end date.', $timeline->sentence());
    }

    public function test_an_ended_campaign_reports_how_long_it_ran(): void
    {
        $timeline = CampaignTimeline::for($this->campaign('2026-09-01', '2026-09-30'), CarbonImmutable::parse('2026-10-18'));

        $this->assertSame(CampaignTimeline::ENDED, $timeline->state);
        $this->assertSame(30, $timeline->ranFor);
        $this->assertSame(18, $timeline->endedDaysAgo);
        $this->assertSame('Ran for 30 days.', $timeline->sentence());
    }

    public function test_suggested_phases_cover_the_range_without_gaps(): void
    {
        $phases = SuggestedPhases::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-28'));

        $this->assertSame(['Teaser', 'Launch', 'Sustain', 'Wrap'], array_column($phases, 'name'));
        $this->assertSame('2026-11-01', $phases[0]['starts_on']);
        $this->assertSame('2026-11-28', $phases[3]['ends_on']);

        for ($i = 1; $i < count($phases); $i++) {
            $this->assertSame(
                CarbonImmutable::parse($phases[$i - 1]['ends_on'])->addDay()->toDateString(),
                $phases[$i]['starts_on'],
            );
        }
    }

    public function test_suggested_phases_shrink_for_short_campaigns(): void
    {
        $this->assertCount(1, SuggestedPhases::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-01')));
        $this->assertCount(2, SuggestedPhases::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02')));
        $this->assertCount(3, SuggestedPhases::between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-03')));
    }

    public function test_due_dates_skip_weekends(): void
    {
        // Monday 2 Nov 2026, 3 working days of lead time → Wednesday 28 Oct.
        $this->assertSame('2026-10-28', DeliverableDueDate::for(CarbonImmutable::parse('2026-11-02'), 3)?->toDateString());
        // Thursday 5 Nov, 3 working days → Monday 2 Nov.
        $this->assertSame('2026-11-02', DeliverableDueDate::for(CarbonImmutable::parse('2026-11-05'), 3)?->toDateString());
        // No lead time: due on the day.
        $this->assertSame('2026-11-05', DeliverableDueDate::for(CarbonImmutable::parse('2026-11-05'), 0)?->toDateString());
        $this->assertNull(DeliverableDueDate::for(null, 3));
    }
}
