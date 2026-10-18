<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

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
     * Step I-2.7 — count rows per bucket in the database. Each bucket's edges are computed here in
     * the range's timezone and bound as UTC instants into a `CASE`, so the result matches
     * `countByBucket()` exactly (DST included) without needing MySQL's timezone tables.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|QueryBuilder  $query  already bound to the range
     * @param  literal-string  $column
     * @return array<string, int>
     */
    public function countByBucketSql(Builder|QueryBuilder $query, string $column): array
    {
        $counts = [];

        foreach ($this->bucketRows($query, $column, null) as $row) {
            $counts[(string) $row->bucket_key] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * `countByBucketSql()` split by a second column: `[group value => [bucket key => count]]`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|QueryBuilder  $query  already bound to the range
     * @param  literal-string  $column
     * @param  literal-string  $groupColumn
     * @return array<string, array<string, int>>
     */
    public function countByBucketAndGroupSql(Builder|QueryBuilder $query, string $column, string $groupColumn): array
    {
        $counts = [];

        foreach ($this->bucketRows($query, $column, $groupColumn) as $row) {
            $counts[(string) $row->bucket_group][(string) $row->bucket_key] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>|QueryBuilder  $query
     * @param  literal-string  $column
     * @param  literal-string|null  $groupColumn
     * @return list<\stdClass>
     */
    private function bucketRows(Builder|QueryBuilder $query, string $column, ?string $groupColumn): array
    {
        [$sql, $bindings] = $this->bucketCase($column);

        $base = $query instanceof Builder ? $query->toBase() : $query;
        $base->addSelect(new Expression($sql.' as bucket_key'))
            ->addBinding($bindings, 'select')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('bucket_key');

        if ($groupColumn !== null) {
            $base->addSelect("{$groupColumn} as bucket_group")->groupBy($groupColumn);
        }

        return array_values($base->get()->filter(fn ($row) => $row->bucket_key !== null)->all());
    }

    /**
     * `CASE WHEN col < edge1 THEN key0 WHEN col < edge2 THEN key1 … END` over the buckets' UTC
     * edges (rows before the first bucket or after the last map to NULL).
     *
     * @param  literal-string  $column
     * @return array{0: literal-string, 1: list<string>}
     */
    private function bucketCase(string $column): array
    {
        $daily = $this->daily();
        $cursor = $daily ? $this->range->from->startOfDay() : $this->range->from->startOfMonth();
        $sql = "CASE WHEN {$column} < ? THEN NULL";
        $bindings = [$cursor->utc()->format('Y-m-d H:i:s')];

        while ($cursor->lessThanOrEqualTo($this->range->to)) {
            $next = $daily ? $cursor->addDay() : $cursor->addMonth();
            $sql .= " WHEN {$column} < ? THEN ?";
            $bindings[] = $next->utc()->format('Y-m-d H:i:s');
            $bindings[] = $cursor->format($this->format());
            $cursor = $next;
        }

        return [$sql.' ELSE NULL END', $bindings];
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
