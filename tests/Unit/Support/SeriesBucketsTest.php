<?php

namespace Tests\Unit\Support;

use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\SeriesBuckets;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Step 17.1 — buckets are keyed in the range's timezone: an instant is converted before its key
 * is formatted, while calendar dates are bucketed as-is.
 */
class SeriesBucketsTest extends TestCase
{
    private function range(string $timezone, string $from = '2026-03-25', string $to = '2026-04-05'): AnalyticsRange
    {
        return AnalyticsRange::fromArray(
            ['range' => 'custom', 'from' => $from, 'to' => $to],
            $timezone,
            CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'),
        );
    }

    public function test_a_late_utc_event_buckets_to_the_local_day(): void
    {
        $event = CarbonImmutable::parse('2026-03-31 23:30:00', 'UTC');

        $this->assertSame('2026-04-01', (new SeriesBuckets($this->range('Asia/Dubai')))->keyFor($event));
        // London is on BST (UTC+1) from 29 March.
        $this->assertSame('2026-04-01', (new SeriesBuckets($this->range('Europe/London')))->keyFor($event));
        $this->assertSame('2026-03-31', (new SeriesBuckets($this->range('UTC')))->keyFor($event));
        // Stored strings are read as UTC too.
        $this->assertSame('2026-04-01', (new SeriesBuckets($this->range('Asia/Dubai')))->keyFor('2026-03-31 23:30:00'));
    }

    public function test_counts_and_sums_per_bucket(): void
    {
        $buckets = new SeriesBuckets($this->range('Asia/Dubai'));

        $this->assertSame(
            ['2026-04-01' => 2, '2026-03-31' => 1],
            $buckets->countByBucket(['2026-03-31 23:30:00', '2026-04-01 08:00:00', '2026-03-31 10:00:00', null]),
        );

        // Calendar dates aren't shifted, even though Dubai is ahead of UTC.
        $this->assertSame(
            ['2026-03-31' => 150.5, '2026-04-01' => 20.0],
            $buckets->sumByBucket([['2026-03-31', 100], ['2026-03-31', '50.50'], ['2026-04-01', 20]], calendarDates: true),
        );
    }

    public function test_daily_buckets_cover_the_range_in_order(): void
    {
        $buckets = (new SeriesBuckets($this->range('Asia/Dubai', '2026-03-30', '2026-04-02')))->buckets();

        $this->assertSame([
            ['key' => '2026-03-30', 'label' => '30 Mar'],
            ['key' => '2026-03-31', 'label' => '31 Mar'],
            ['key' => '2026-04-01', 'label' => '1 Apr'],
            ['key' => '2026-04-02', 'label' => '2 Apr'],
        ], $buckets);
    }

    public function test_long_ranges_bucket_per_month(): void
    {
        $series = new SeriesBuckets($this->range('Europe/London', '2026-01-15', '2026-04-10'));

        $this->assertFalse($series->daily());
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($series->buckets(), 'key'));
        $this->assertSame('Jan 2026', $series->buckets()[0]['label']);
        $this->assertSame('2026-04', $series->keyFor('2026-03-31 23:30:00'));
    }
}
