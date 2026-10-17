<?php

namespace Tests\Unit\Support;

use App\Support\SuperAdmin\AnalyticsFilters;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AnalyticsFiltersTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-17 15:30:00', 'UTC');
    }

    public function test_presets_resolve_relative_to_today(): void
    {
        $cases = [
            '7d' => ['2026-10-11', '2026-10-17'],
            '30d' => ['2026-09-18', '2026-10-17'],
            '90d' => ['2026-07-20', '2026-10-17'],
            '12m' => ['2025-11-01', '2026-10-17'],
            'ytd' => ['2026-01-01', '2026-10-17'],
        ];

        foreach ($cases as $range => [$from, $to]) {
            $filters = AnalyticsFilters::fromArray(['range' => $range], $this->now);

            $this->assertSame($from, $filters->from->toDateString(), $range);
            $this->assertSame($to, $filters->to->toDateString(), $range);
            $this->assertSame('23:59:59', $filters->to->format('H:i:s'), $range);
        }
    }

    public function test_unknown_or_incomplete_input_falls_back_to_thirty_days(): void
    {
        foreach ([[], ['range' => 'forever'], ['range' => 'custom'], ['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01']] as $input) {
            $filters = AnalyticsFilters::fromArray($input, $this->now);

            $this->assertSame('30d', $filters->range);
            $this->assertSame('2026-09-18', $filters->from->toDateString());
        }
    }

    public function test_custom_ranges_pin_their_dates(): void
    {
        $filters = AnalyticsFilters::fromArray(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-09-30'], $this->now);

        $this->assertSame('2026-07-01 00:00:00', $filters->from->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $filters->to->toDateTimeString());
        $this->assertSame('month', $filters->granularity());
    }

    public function test_org_ids_and_metrics_are_sanitised(): void
    {
        $filters = AnalyticsFilters::fromArray([
            'org_ids' => ['3', 3, 'x', -1, 7],
            'metrics' => ['revenue', 'bogus', 'posts'],
        ], $this->now);

        $this->assertSame([3, 7], $filters->orgIds);
        // Kept in canonical order.
        $this->assertSame(['posts', 'revenue'], $filters->metrics);
        $this->assertTrue($filters->includes('posts'));
        $this->assertFalse($filters->includes('ai'));
    }

    public function test_an_empty_metric_selection_means_every_section(): void
    {
        $filters = AnalyticsFilters::fromArray([], $this->now);

        $this->assertTrue($filters->includes('ai'));
        $this->assertSame(AnalyticsFilters::METRICS, $filters->selectedMetrics());
    }

    public function test_stored_form_keeps_presets_relative(): void
    {
        $preset = AnalyticsFilters::fromArray(['range' => '7d', 'org_ids' => [5]], $this->now);
        $custom = AnalyticsFilters::fromArray(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-31'], $this->now);

        $this->assertSame(['range' => '7d', 'from' => null, 'to' => null, 'org_ids' => [5], 'metrics' => []], $preset->toStoredArray());
        $this->assertSame('2026-07-01', $custom->toStoredArray()['from']);
        $this->assertSame([9], $preset->forOrganization(9)->orgIds);
        $this->assertSame('day', $preset->granularity());
    }
}
