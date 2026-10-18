<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Step 17.1 — the time buckets of an analytics series: one per day (ranges up to ~2 months) or
 * per month, keyed `Y-m-d` / `Y-m`, in the range's timezone.
 *
 * Timestamps are stored in UTC, so every instant is converted into the range's timezone *before*
 * its key is formatted — a post published at 23:30 UTC on 31 March belongs to 1 April in Dubai.
 * Calendar dates (`invoices.issue_date`) have no timezone and are bucketed as-is
 * (`calendarDates: true`). Bucketing happens in PHP so it behaves the same on Postgres
 * (production) and SQLite (tests).
 */
final readonly class SeriesBuckets
{
    public function __construct(private AnalyticsRange $range) {}

    public function daily(): bool
    {
        return $this->range->granularity() === 'day';
    }

    public function format(): string
    {
        return $this->daily() ? 'Y-m-d' : 'Y-m';
    }

    /**
     * Every bucket in the range, in order.
     *
     * @return list<array{key: string, label: string}>
     */
    public function buckets(): array
    {
        $daily = $this->daily();
        $format = $this->format();
        $cursor = $daily ? $this->range->from->startOfDay() : $this->range->from->startOfMonth();
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($this->range->to)) {
            $buckets[] = ['key' => $cursor->format($format), 'label' => $cursor->format($daily ? 'j M' : 'M Y')];
            $cursor = $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return $buckets;
    }

    /**
     * The bucket key a stored value falls in.
     */
    public function keyFor(mixed $value, bool $calendarDates = false): string
    {
        if ($calendarDates) {
            $date = $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : substr((string) $value, 0, 10);

            return CarbonImmutable::createFromFormat('Y-m-d', $date, $this->range->timezone)?->format($this->format()) ?? $date;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');

        return $instant->setTimezone($this->range->timezone)->format($this->format());
    }

    /**
     * How many of the given timestamps fall in each bucket.
     *
     * @param  iterable<mixed>  $values
     * @return array<string, int>
     */
    public function countByBucket(iterable $values, bool $calendarDates = false): array
    {
        $counts = [];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $key = $this->keyFor($value, $calendarDates);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The sum of amounts per bucket, from `[date, amount]` pairs.
     *
     * @param  iterable<array{0: mixed, 1: float|int|string|null}>  $pairs
     * @return array<string, float>
     */
    public function sumByBucket(iterable $pairs, bool $calendarDates = false): array
    {
        $sums = [];

        foreach ($pairs as [$value, $amount]) {
            if ($value === null) {
                continue;
            }

            $key = $this->keyFor($value, $calendarDates);
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) $amount;
        }

        return array_map(fn (float $sum) => round($sum, 2), $sums);
    }
}
