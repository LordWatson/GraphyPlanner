<?php

namespace Tests\Unit\Support;

use App\Support\Analytics\AnalyticsRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Step 17.1 — range resolution in a timezone: presets follow the local clock (including across
 * the October DST change), custom ranges pin local dates, bad input falls back to 30 days.
 */
class AnalyticsRangeTest extends TestCase
{
    public function test_presets_resolve_against_today_in_the_ranges_timezone(): void
    {
        // 23:30 UTC on the 17th is already the 18th in Dubai (UTC+4) and London (BST, UTC+1).
        $now = CarbonImmutable::parse('2026-10-17 23:30:00', 'UTC');

        $this->assertSame('2026-10-17', AnalyticsRange::fromArray(['range' => '30d'], 'UTC', $now)->to->toDateString());
        $this->assertSame('2026-10-18', AnalyticsRange::fromArray(['range' => '30d'], 'Asia/Dubai', $now)->to->toDateString());
        $this->assertSame('2026-10-18', AnalyticsRange::fromArray(['range' => '30d'], 'Europe/London', $now)->to->toDateString());
    }

    public function test_every_preset_in_dubai(): void
    {
        $now = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');

        $cases = [
            '7d' => ['2026-10-11', '2026-10-17'],
            '30d' => ['2026-09-18', '2026-10-17'],
            '90d' => ['2026-07-20', '2026-10-17'],
            '12m' => ['2025-11-01', '2026-10-17'],
            'ytd' => ['2026-01-01', '2026-10-17'],
        ];

        foreach ($cases as $preset => [$from, $to]) {
            $range = AnalyticsRange::fromArray(['range' => $preset], 'Asia/Dubai', $now);

            $this->assertSame($preset, $range->preset);
            $this->assertSame('Asia/Dubai', $range->timezone);
            $this->assertSame($from, $range->from->toDateString(), $preset);
            $this->assertSame($to, $range->to->toDateString(), $preset);
            $this->assertSame('00:00:00', $range->from->format('H:i:s'), $preset);
            $this->assertSame('23:59:59', $range->to->format('H:i:s'), $preset);
            // Local midnight in Dubai is 20:00 UTC the previous day.
            $this->assertSame('20:00:00', $range->fromUtc()->format('H:i:s'), $preset);
        }
    }

    public function test_presets_in_london_across_the_october_dst_change(): void
    {
        // Clocks go back at 02:00 BST on Sunday 25 October 2026.
        $now = CarbonImmutable::parse('2026-10-27 10:00:00', 'UTC');

        $week = AnalyticsRange::fromArray(['range' => '7d'], 'Europe/London', $now);

        $this->assertSame('2026-10-21', $week->from->toDateString());
        $this->assertSame('2026-10-27', $week->to->toDateString());
        // The start is local midnight in BST (UTC+1), the end local midnight in GMT (UTC+0).
        $this->assertSame('2026-10-20 23:00:00', $week->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 23:59:59', $week->toUtc()->format('Y-m-d H:i:s'));

        $all = AnalyticsRange::fromArray(['range' => '30d'], 'Europe/London', $now);
        $this->assertSame('2026-09-28', $all->from->toDateString());
        $this->assertSame('2026-09-27 23:00:00', $all->fromUtc()->format('Y-m-d H:i:s'));

        $year = AnalyticsRange::fromArray(['range' => 'ytd'], 'Europe/London', $now);
        $this->assertSame('2026-01-01 00:00:00', $year->fromUtc()->format('Y-m-d H:i:s'));
    }

    public function test_custom_ranges_pin_local_dates(): void
    {
        $now = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');
        $range = AnalyticsRange::fromArray(['range' => 'custom', 'from' => '2026-04-01', 'to' => '2026-04-30'], 'Asia/Dubai', $now);

        $this->assertSame('custom', $range->preset);
        $this->assertSame(['2026-04-01', '2026-04-30'], $range->dateBounds());
        $this->assertSame('2026-03-31 20:00:00', $range->fromUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-30 19:59:59', $range->toUtc()->format('Y-m-d H:i:s'));
        $this->assertSame(['range' => 'custom', 'from' => '2026-04-01', 'to' => '2026-04-30'], $range->toStoredArray());
        $this->assertSame('day', $range->granularity());
    }

    public function test_bad_input_falls_back_to_thirty_days(): void
    {
        $now = CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC');

        foreach ([
            [],
            ['range' => 'forever'],
            ['range' => 'custom'],
            ['range' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-01'],
            ['range' => 'custom', 'from' => 'nope', 'to' => '2026-09-01'],
            ['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'],
        ] as $input) {
            $range = AnalyticsRange::fromArray($input, 'Europe/London', $now);

            $this->assertSame('30d', $range->preset, json_encode($input) ?: '');
            $this->assertSame('2026-09-18', $range->from->toDateString());
            $this->assertSame('2026-10-17', $range->to->toDateString());
        }
    }

    public function test_an_unknown_timezone_falls_back_to_utc(): void
    {
        $range = AnalyticsRange::fromArray(['range' => '7d'], 'Mars/Olympus', CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));

        $this->assertSame('UTC', $range->timezone);
    }

    public function test_stored_and_resolved_forms(): void
    {
        $range = AnalyticsRange::fromArray(['range' => '90d'], 'Asia/Dubai', CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));

        $this->assertSame(['range' => '90d', 'from' => null, 'to' => null], $range->toStoredArray());
        $this->assertSame([
            'range' => '90d',
            'from' => '2026-07-20',
            'to' => '2026-10-17',
            'granularity' => 'month',
            'timezone' => 'Asia/Dubai',
        ], $range->toArray());
    }
}
