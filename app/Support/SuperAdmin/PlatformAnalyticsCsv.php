<?php

namespace App\Support\SuperAdmin;

/**
 * Step 15.5 — flattens a `GetPlatformAnalyticsSummaryAction` result into CSV rows for a saved
 * report's export: a `section, metric, value, currency` block for the selected metric sections,
 * then the time series and the top organizations. Pure formatting — no queries.
 */
final class PlatformAnalyticsCsv
{
    /**
     * @param  array<string, mixed>  $summary
     * @return list<list<string|int|float|null>>
     */
    public static function rows(array $summary): array
    {
        $filters = $summary['filters'];

        $rows = [
            ['Range', $filters['range'], $filters['from'], $filters['to']],
            [],
            ['Section', 'Metric', 'Value', 'Currency'],
        ];

        foreach ($summary['metrics'] as $section) {
            $figures = $summary[$section] ?? null;

            if (! is_array($figures)) {
                continue;
            }

            foreach ($figures as $metric => $value) {
                if ($metric === 'by_role' && is_array($value)) {
                    foreach ($value as $role) {
                        $rows[] = [$section, "users_{$role['role']}", $role['count'], null];
                    }

                    continue;
                }

                if (is_array($value)) {
                    // A per-currency money list — one row per currency, never summed.
                    foreach ($value === [] ? [['currency' => null, 'total' => 0]] : $value as $total) {
                        $rows[] = [$section, $metric, $total['total'], $total['currency']];
                    }

                    continue;
                }

                $rows[] = [$section, $metric, $value, null];
            }
        }

        $rows[] = [];
        $rows[] = ['Period', 'Signups', 'Published', 'Failed', "Invoiced ({$summary['primary_currency']})"];

        foreach ($summary['series'] as $bucket) {
            $rows[] = [$bucket['key'], $bucket['signups'], $bucket['published'], $bucket['failed'], $bucket['invoiced']];
        }

        $rows[] = [];
        $rows[] = ['Organization', 'Published', 'Clients', 'Users', "Invoiced ({$summary['primary_currency']})", 'Suspended'];

        foreach ($summary['top_organizations'] as $organization) {
            $rows[] = [
                $organization['name'],
                $organization['published'],
                $organization['clients'],
                $organization['users'],
                $organization['invoiced'],
                $organization['suspended'] ? 'yes' : 'no',
            ];
        }

        return $rows;
    }
}
