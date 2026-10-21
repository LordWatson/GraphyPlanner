<?php

namespace App\Support\Analytics;

/**
 * Step 17.7 — flattens a `GetOrganizationAnalyticsSummaryAction` result into CSV rows: a short
 * header (organization, range, timezone, client when drilled down), the section block, then the
 * workload, attention, series and top-list tables. Pure formatting over the summary the
 * *requesting* viewer was given, so a Designer's export has no revenue rows and no invoiced column.
 */
final class OrganizationAnalyticsCsv
{
    /**
     * @param  array<string, mixed>  $summary
     * @return list<list<string|int|float|null>>
     */
    public static function rows(array $summary): array
    {
        $filters = $summary['filters'];
        $currency = $summary['primary_currency'];
        $withInvoiced = is_array($summary['revenue'] ?? null);

        $rows = [
            ['Organization', $summary['organization']['name']],
            ['Range', $filters['range'], $filters['from'], $filters['to']],
            ['Timezone', $filters['timezone']],
        ];

        if (is_array($summary['client'] ?? null)) {
            $rows[] = ['Client', $summary['client']['name'], $summary['client']['health'], $summary['client']['reason']];
        }

        $rows[] = [];
        $rows[] = AnalyticsCsv::SECTION_HEADER;

        // A cost the viewer may not see is left out entirely, not exported as a blank.
        $sections = $summary;

        if (is_array($sections['ai'] ?? null) && $sections['ai']['cost_estimate'] === null) {
            unset($sections['ai']['cost_estimate']);
        }

        // Step M-3.1: engagement's figures, per platform keyed by platform; its series and top
        // posts get their own tables below, and per-account follower rows are left out.
        if (is_array($sections['engagement'] ?? null)) {
            $engagement = $sections['engagement'];
            $sections['engagement'] = [
                'published_channels' => $engagement['published_channels'],
                'measured_channels' => $engagement['measured_channels'],
                'totals' => $engagement['totals'],
                'engagement_rate' => $engagement['engagement_rate'],
                'followers' => ['current' => $engagement['followers']['current'], 'growth' => $engagement['followers']['growth']],
                'by_platform' => array_column(array_map(fn (array $row) => [
                    'platform' => $row['platform'],
                    'figures' => [
                        'available' => $row['available'],
                        'published' => $row['published'],
                        'measured' => $row['measured'],
                        'engagement' => $row['engagement'],
                        'engagement_rate' => $row['engagement_rate'],
                    ],
                ], $engagement['by_platform']), 'figures', 'platform'),
                'last_synced_at' => $engagement['last_synced_at'],
            ];

            if ($sections['engagement']['by_platform'] === []) {
                unset($sections['engagement']['by_platform']);
            }
        }

        array_push($rows, ...AnalyticsCsv::sectionRows($sections, $summary['metrics'], self::listRows(...)));

        if (is_array($summary['team'] ?? null)) {
            array_push($rows, ...AnalyticsCsv::table(
                ['Team member', 'Role', 'Posts created', 'Status changes', 'Sent to client', 'Scheduled'],
                array_map(fn (array $member) => [
                    $member['name'],
                    $member['role_label'],
                    $member['posts_created'],
                    $member['transitions'],
                    $member['sent_to_client'],
                    $member['scheduled'],
                ], $summary['team']['members']),
            ));
        }

        if (is_array($summary['clients'] ?? null) && $summary['clients']['attention'] !== []) {
            array_push($rows, ...AnalyticsCsv::table(
                ['Client needing attention', 'Health', 'Reason'],
                array_map(fn (array $client) => [$client['name'], $client['health'], $client['reason']], $summary['clients']['attention']),
            ));
        }

        $seriesHeader = ['Period', 'Created', 'Published', 'Failed', 'Sent for approval', 'Approved'];

        if ($withInvoiced) {
            $seriesHeader[] = "Invoiced ({$currency})";
        }

        array_push($rows, ...AnalyticsCsv::table($seriesHeader, array_map(function (array $bucket) use ($withInvoiced) {
            $row = [$bucket['key'], $bucket['created'], $bucket['published'], $bucket['failed'], $bucket['sent_for_approval'], $bucket['approved']];

            if ($withInvoiced) {
                $row[] = $bucket['invoiced'] ?? 0.0;
            }

            return $row;
        }, $summary['series'])));

        if (is_array($summary['top_clients'] ?? null)) {
            $header = ['Client', 'Health', 'Published', 'Scheduled', 'Waiting on client'];

            if ($withInvoiced) {
                $header[] = "Invoiced ({$currency})";
            }

            array_push($rows, ...AnalyticsCsv::table($header, array_map(function (array $client) use ($withInvoiced) {
                $row = [$client['name'], $client['health'], $client['published'], $client['scheduled'], $client['waiting_on_client']];

                if ($withInvoiced) {
                    $row[] = $client['invoiced'] ?? 0.0;
                }

                return $row;
            }, $summary['top_clients'])));
        }

        if (is_array($summary['top_campaigns'] ?? null)) {
            array_push($rows, ...self::campaignTable($summary['top_campaigns']));
        }

        if (is_array($summary['engagement'] ?? null)) {
            array_push($rows, ...AnalyticsCsv::table(
                ['Period', 'Interactions', 'Impressions', 'Followers'],
                array_map(fn (array $bucket) => [$bucket['key'], $bucket['engagement'], $bucket['impressions'], $bucket['followers']], $summary['engagement']['series']),
            ));

            if ($summary['engagement']['top_posts'] !== []) {
                array_push($rows, ...AnalyticsCsv::table(
                    ['Top post', 'Client', 'Platforms', 'Published', 'Interactions', 'Reach', 'Impressions', 'Engagement rate', 'URL'],
                    array_map(fn (array $post) => [
                        $post['caption'],
                        $post['client_name'],
                        implode(' ', $post['platforms']),
                        $post['published_at'],
                        $post['engagement'],
                        $post['reach'],
                        $post['impressions'],
                        $post['engagement_rate'],
                        $post['post_url'],
                    ], $summary['engagement']['top_posts']),
                ));
            }
        }

        return $rows;
    }

    /**
     * Section-block rows for the lists inside a section; record lists that get their own table
     * (attention, members, top campaigns) are left out of the block.
     *
     * @param  list<mixed>  $list
     * @return list<list<string|int|float|null>>|null
     */
    private static function listRows(string $section, string $metric, array $list): ?array
    {
        return match ($metric) {
            'pipeline' => array_map(fn (array $row) => [$section, "pipeline_{$row['status']}", $row['count'], null], $list),
            'by_platform' => array_map(fn (array $row) => [$section, "published_{$row['platform']}", $row['published'], null], $list),
            'by_method' => array_merge(...array_map(fn (array $row) => [
                [$section, "{$row['method']}_succeeded", $row['succeeded'], null],
                [$section, "{$row['method']}_failed", $row['failed'], null],
            ], $list ?: [['method' => 'none', 'succeeded' => 0, 'failed' => 0]])),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $campaigns
     * @return list<list<string|int|float|null>>
     */
    private static function campaignTable(array $campaigns): array
    {
        return AnalyticsCsv::table(
            ['Campaign', 'Client', 'Published', 'Scheduled', 'Waiting on client'],
            array_map(fn (array $campaign) => [
                $campaign['name'],
                $campaign['client_name'],
                $campaign['published'],
                $campaign['scheduled'],
                $campaign['waiting_on_client'],
            ], array_values($campaigns)),
        );
    }
}
