<?php

namespace App\Support\SuperAdmin;

use App\Support\Analytics\AnalyticsCsv;

/**
 * Step 15.5 — flattens a `GetPlatformAnalyticsSummaryAction` result into CSV rows for a saved
 * report's export: a `section, metric, value, currency` block for the selected metric sections,
 * then the time series and the top organizations. Pure formatting — no queries. The flattening
 * itself is shared with organization exports (`App\Support\Analytics\AnalyticsCsv`, Step 17.7).
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
        $currency = $summary['primary_currency'];

        $sections = AnalyticsCsv::sectionRows(
            $summary,
            $summary['metrics'],
            fn (string $section, string $metric, array $list) => $metric === 'by_role'
                ? array_map(fn (array $role) => [$section, "users_{$role['role']}", $role['count'], null], $list)
                : null,
        );

        return [
            ['Range', $filters['range'], $filters['from'], $filters['to']],
            [],
            AnalyticsCsv::SECTION_HEADER,
            ...$sections,
            ...AnalyticsCsv::table(
                ['Period', 'Signups', 'Published', 'Failed', "Invoiced ({$currency})"],
                array_map(fn (array $bucket) => [$bucket['key'], $bucket['signups'], $bucket['published'], $bucket['failed'], $bucket['invoiced']], $summary['series']),
            ),
            ...AnalyticsCsv::table(
                ['Organization', 'Published', 'Clients', 'Users', "Invoiced ({$currency})", 'Suspended'],
                array_map(fn (array $organization) => [
                    $organization['name'],
                    $organization['published'],
                    $organization['clients'],
                    $organization['users'],
                    $organization['invoiced'],
                    $organization['suspended'] ? 'yes' : 'no',
                ], $summary['top_organizations']),
            ),
        ];
    }
}
