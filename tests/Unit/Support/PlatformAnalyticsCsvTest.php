<?php

namespace Tests\Unit\Support;

use App\Support\Analytics\AnalyticsCsv;
use App\Support\SuperAdmin\PlatformAnalyticsCsv;
use PHPUnit\Framework\TestCase;

/**
 * Step 17.7 regression — the super admin CSV export must be byte-for-byte what it was before the
 * row flattening moved into the shared `AnalyticsCsv`. `legacyRows()` is a frozen copy of the
 * Phase 15 implementation.
 */
class PlatformAnalyticsCsvTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'filters' => ['range' => '30d', 'from' => '2026-09-18', 'to' => '2026-10-17', 'org_ids' => [], 'metrics' => [], 'granularity' => 'day'],
            'metrics' => ['organizations', 'users', 'clients', 'posts', 'revenue', 'ai'],
            'organizations' => ['total' => 3, 'active' => 2, 'suspended' => 1, 'new' => 2],
            'users' => ['total' => 3, 'new' => 3, 'by_role' => [
                ['role' => 'owner', 'label' => 'Owner', 'count' => 2],
                ['role' => 'viewer', 'label' => 'Viewer', 'count' => 0],
            ]],
            'clients' => ['total' => 3, 'active' => 2, 'new' => 3],
            'posts' => ['total' => 9, 'published_all_time' => 5, 'published' => 4, 'published_last_30_days' => 4, 'failed' => 1, 'success_rate' => 80.0, 'scheduled' => 0],
            'revenue' => [
                'invoiced' => [['currency' => 'USD', 'total' => 1700.5], ['currency' => 'EUR', 'total' => 300.0]],
                'collected' => [['currency' => 'USD', 'total' => 700.0]],
                'outstanding' => [],
                'monthly_run_rate' => [['currency' => 'USD', 'total' => 1700.5]],
                'invoices_issued' => 4,
                'invoices_paid' => 2,
            ],
            'ai' => ['generated' => 0, 'failed' => 0, 'cost_estimate' => 0.0],
            'engagement' => null,
            'primary_currency' => 'USD',
            'series' => [
                ['key' => '2026-10-16', 'label' => '16 Oct', 'signups' => 1, 'published' => 2, 'failed' => 0, 'invoiced' => 200.0],
                ['key' => '2026-10-17', 'label' => '17 Oct', 'signups' => 0, 'published' => 0, 'failed' => 1, 'invoiced' => 0.0],
            ],
            'top_organizations' => [
                ['id' => 1, 'name' => 'Northlight, "Studio"', 'slug' => 'n', 'suspended' => false, 'published' => 3, 'clients' => 2, 'users' => 2, 'invoiced' => 1500.0],
                ['id' => 2, 'name' => 'Harbour & Co', 'slug' => 'h', 'suspended' => true, 'published' => 1, 'clients' => 1, 'users' => 1, 'invoiced' => 200.5],
            ],
        ];
    }

    public function test_the_export_is_byte_for_byte_unchanged(): void
    {
        $summary = $this->summary();

        $this->assertSame(
            AnalyticsCsv::toString($this->legacyRows($summary)),
            AnalyticsCsv::toString(PlatformAnalyticsCsv::rows($summary)),
        );
        $this->assertSame($this->legacyRows($summary), PlatformAnalyticsCsv::rows($summary));
    }

    public function test_unselected_sections_and_null_rates_match_too(): void
    {
        $summary = $this->summary();
        $summary['metrics'] = ['posts', 'revenue'];
        $summary['posts']['success_rate'] = null;
        $summary['organizations'] = null;

        $this->assertSame($this->legacyRows($summary), PlatformAnalyticsCsv::rows($summary));
    }

    /**
     * The Phase 15 `PlatformAnalyticsCsv::rows()`, verbatim.
     *
     * @param  array<string, mixed>  $summary
     * @return list<list<string|int|float|null>>
     */
    private function legacyRows(array $summary): array
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
