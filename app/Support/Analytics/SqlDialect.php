<?php

namespace App\Support\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Step I-2.7 — the few SQL expressions analytics needs that differ between production (MySQL 8)
 * and the test database (SQLite). Everything else used for in-database aggregation — window
 * functions (`LAG`, `SUM() OVER`), `CASE`, `GROUP BY` — is standard on both.
 *
 * Columns are interpolated as SQL, so they must be fixed column names (`literal-string`).
 */
final class SqlDialect
{
    /**
     * Whole seconds from `$from` to `$to` (both timestamp columns).
     *
     * @param  literal-string  $from
     * @param  literal-string  $to
     * @return literal-string
     */
    public static function secondsBetween(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(ROUND((julianday({$to}) - julianday({$from})) * 86400) AS INTEGER)",
            'pgsql' => "CAST(EXTRACT(EPOCH FROM ({$to} - {$from})) AS INTEGER)",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
        };
    }

    /**
     * Whole days (truncated, never negative) from `$from` to `$to` — the SQL form of
     * `max(0, (int) $from->diffInDays($to))`.
     *
     * @param  literal-string  $from
     * @param  literal-string  $to
     * @return literal-string
     */
    public static function wholeDaysBetween(string $from, string $to): string
    {
        $seconds = self::secondsBetween($from, $to);
        $days = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "({$seconds}) DIV 86400",
            'pgsql' => "TRUNC(({$seconds}) / 86400.0)",
            default => "({$seconds}) / 86400",
        };

        return "CASE WHEN ({$seconds}) <= 0 THEN 0 ELSE {$days} END";
    }
}
