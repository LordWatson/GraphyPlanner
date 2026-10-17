<?php

namespace App\Support\Analytics;

use Closure;

/**
 * Step 17.7 — the row-flattening shared by every analytics CSV export (the platform console's
 * `PlatformAnalyticsCsv` and the organization's `OrganizationAnalyticsCsv`). Pure formatting —
 * no queries — so an export can only ever contain what the summary it's given contains; sections
 * the viewer can't see are `null` in that summary and simply never appear.
 *
 * Layout: a `Section, Metric, Value, Currency` block, then any number of blank-line-separated
 * tables (series, top lists). Money is one row per currency, never summed.
 */
final class AnalyticsCsv
{
    public const SECTION_HEADER = ['Section', 'Metric', 'Value', 'Currency'];

    /**
     * The section block rows for the given sections. Scalars become one row; a per-currency money
     * list becomes one row per currency (or a single zero row when empty); nested figures are
     * flattened as `parent_child`. Lists of records are passed to `$listRows` (return null to
     * leave a list out of the block — e.g. it's exported as its own table instead).
     *
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $sections
     * @param  (Closure(string $section, string $metric, list<mixed> $list): (list<list<string|int|float|null>>|null))|null  $listRows
     * @return list<list<string|int|float|null>>
     */
    public static function sectionRows(array $summary, array $sections, ?Closure $listRows = null): array
    {
        $rows = [];

        foreach ($sections as $section) {
            $figures = $summary[$section] ?? null;

            if (is_array($figures)) {
                array_push($rows, ...self::flatten($section, '', $figures, $listRows));
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $figures
     * @param  (Closure(string, string, list<mixed>): (list<list<string|int|float|null>>|null))|null  $listRows
     * @return list<list<string|int|float|null>>
     */
    private static function flatten(string $section, string $prefix, array $figures, ?Closure $listRows): array
    {
        $rows = [];

        foreach ($figures as $metric => $value) {
            $name = $prefix.$metric;

            if (! is_array($value)) {
                $rows[] = [$section, $name, self::cell($value), null];

                continue;
            }

            if (self::isMoneyList($value)) {
                // A per-currency money list — one row per currency, never summed.
                foreach ($value === [] ? [['currency' => null, 'total' => 0]] : $value as $total) {
                    $rows[] = [$section, $name, self::cell($total['total']), self::cell($total['currency'])];
                }

                continue;
            }

            if (array_is_list($value)) {
                $mapped = $listRows?->__invoke($section, $name, $value);

                if ($mapped !== null) {
                    array_push($rows, ...$mapped);
                }

                continue;
            }

            array_push($rows, ...self::flatten($section, "{$name}_", $value, $listRows));
        }

        return $rows;
    }

    /**
     * A CSV cell: scalars as-is, booleans as yes/no, anything else blank.
     */
    private static function cell(mixed $value): string|int|float|null
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_string($value), is_int($value), is_float($value) => $value,
            default => null,
        };
    }

    /**
     * Whether a value is a per-currency money list (`[['currency' => …, 'total' => …], …]`).
     * An empty list counts: the money keys always hold lists, and an empty one exports as zero.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isMoneyList(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_array($item) || array_keys($item) !== ['currency', 'total']) {
                return false;
            }
        }

        return true;
    }

    /**
     * A blank line, a header row, then the rows.
     *
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     * @return list<list<string|int|float|null>>
     */
    public static function table(array $header, iterable $rows): array
    {
        $table = [[], $header];

        foreach ($rows as $row) {
            $table[] = $row;
        }

        return $table;
    }

    /**
     * Writes rows to a CSV string (tests and email attachments; downloads stream instead).
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function toString(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
