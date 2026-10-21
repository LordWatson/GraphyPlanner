@php
    /*
     * Alima analytics report (Step 17.7) — the invoice's "Studio Paper" language for dompdf: Cloud
     * Cream paper, a Midnight Ink band with the master mark on a Deep Teal tile, Poppins SemiBold
     * headlines with one upright teal accent word, DM Mono spec labels, hairline tables, Ink for the
     * hero figure. Signal Orange appears once, as the cover's rounded dash. Status is always a tinted
     * pill with a dot *and* a label, never colour alone.
     *
     * Only sections present in `$summary` are drawn — the summary was built for the requesting
     * viewer, so sections they can't see are null and simply absent.
     */
    use App\Support\MoneyFormatter;
    use App\Support\Pdf\PdfBrand;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Str;

    $logo = PdfBrand::markTile();
    $wordmark = PdfBrand::wordmark();

    $filters = $summary['filters'];
    $currency = $summary['primary_currency'];
    $org = $summary['organization'];
    $int = fn ($value) => number_format((int) $value);
    $money = fn ($amount, $code = null) => MoneyFormatter::format((float) $amount, $code ?? $currency);
    $moneyList = fn (array $totals) => $totals === []
        ? $money(0)
        : implode(' · ', array_map(fn ($total) => $money($total['total'], $total['currency']), $totals));
    $hours = function ($value) {
        if ($value === null) {
            return '—';
        }

        return $value >= 48 ? round($value / 24, 1).' days' : $value.' h';
    };
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
    $dateLabel = fn (string $date) => CarbonImmutable::parse($date)->format('j M Y');
    $rangeLabel = $dateLabel($filters['from']).' → '.$dateLabel($filters['to']);

    $tones = [
        'green' => ['bg' => '#E2ECE3', 'fg' => '#276B43', 'label' => 'Green'],
        'amber' => ['bg' => '#F2E9D6', 'fg' => '#8A5A00', 'label' => 'Amber'],
        'red' => ['bg' => '#F4E2DD', 'fg' => '#B52A1B', 'label' => 'Red'],
    ];

    $has = fn (string $section) => is_array($summary[$section] ?? null);
    $sectionTitles = [
        'clients' => ['How clients are', 'tracking'],
        'posts' => ['What went', 'out'],
        'approvals' => ['Where approvals', 'stall'],
        'team' => ['Who carried the', 'load'],
        'revenue' => ['What was', 'billed'],
        'ai' => ['What AI', 'did'],
        'engagement' => ['How people', 'responded'],
    ];
    $count = fn ($value) => $value === null ? '—' : number_format((int) $value);

    // A lightweight SVG bar chart of published (teal) and failed (red, hatched by opacity) per bucket.
    $series = $summary['series'];
    $chart = null;

    if (collect($series)->sum(fn ($bucket) => $bucket['published'] + $bucket['failed'] + $bucket['created']) > 0) {
        $width = 490;
        $height = 120;
        $max = max(1, collect($series)->max(fn ($bucket) => max($bucket['published'] + $bucket['failed'], $bucket['created'])));
        $slot = $width / max(1, count($series));
        $bar = max(1.5, min(16, $slot * 0.6));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.($height + 2).'">';

        foreach ([0.25, 0.5, 0.75, 1] as $line) {
            $y = round($height - $height * $line, 2);
            $svg .= '<line x1="0" x2="'.$width.'" y1="'.$y.'" y2="'.$y.'" stroke="#E1DDD0" stroke-width="0.6" stroke-dasharray="2 3"/>';
        }

        foreach ($series as $index => $bucket) {
            $x = round($index * $slot + ($slot - $bar) / 2, 2);
            $published = $height * $bucket['published'] / $max;
            $failed = $height * $bucket['failed'] / $max;
            $created = $height * $bucket['created'] / $max;

            if ($created > 0) {
                $svg .= '<rect x="'.round($x - 1, 2).'" y="'.round($height - $created, 2).'" width="'.round($bar + 2, 2).'" height="'.round($created, 2).'" fill="#4E91D4" fill-opacity="0.18"/>';
            }

            if ($published > 0) {
                $svg .= '<rect x="'.$x.'" y="'.round($height - $published, 2).'" width="'.round($bar, 2).'" height="'.round($published, 2).'" rx="1.5" fill="#205557"/>';
            }

            if ($failed > 0) {
                $svg .= '<rect x="'.$x.'" y="'.round($height - $published - $failed, 2).'" width="'.round($bar, 2).'" height="'.round($failed, 2).'" rx="1.5" fill="#B52A1B"/>';
            }
        }

        $svg .= '<line x1="0" x2="'.$width.'" y1="'.$height.'" y2="'.$height.'" stroke="#181723" stroke-width="1"/></svg>';
        $chart = 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    $firstLabel = $series[0]['label'] ?? '';
    $lastLabel = $series[count($series) - 1]['label'] ?? '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @include('pdf.partials.brand-fonts')

        @page { margin: 34px 0 78px 0; }

        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; background-color: #F5F2E8; }

        body {
            font-family: 'Poppins', 'DejaVu Sans', sans-serif;
            font-size: 10.5px;
            line-height: 1.5;
            color: #181723;
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }

        .serif { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-weight: bold; letter-spacing: -0.4px; }
        .mono { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }
        .muted { color: #6B6977; }
        .right { text-align: right; }

        .eyebrow {
            font-family: 'DM Mono', 'DejaVu Sans Mono', monospace;
            font-size: 8.5px;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: #6B6977;
        }

        .dash {
            display: inline-block;
            width: 14px;
            height: 3px;
            border-radius: 2px;
            background-color: #F03D2A;
            margin-right: 6px;
        }

        .band { background-color: #181723; color: #F5F2E8; padding: 56px 52px 22px; margin-top: -34px; }
        .band .logo { width: 34px; height: 34px; }
        .band .studio { font-size: 22px; line-height: 1; color: #F5F2E8; padding-left: 12px; }
        .band .caption { font-size: 8px; letter-spacing: 1.4px; color: #AEACB5; padding-left: 12px; padding-top: 5px; }
        .band .eyebrow { color: #AEACB5; }
        .band .range { font-size: 12px; color: #F5F2E8; padding-top: 3px; }

        .sheet { padding: 26px 52px 0; }
        .page-break { page-break-before: always; }

        .cover-title { font-size: 44px; line-height: 1.04; letter-spacing: -1px; margin: 14px 0 0; }
        .cover-title em, .headline em { font-style: normal; color: #205557; }
        .lead { font-size: 13px; color: #6B6977; margin-top: 10px; line-height: 1.45; }

        .headline { font-size: 30px; line-height: 1.05; letter-spacing: -0.6px; margin: 8px 0 4px; }
        .note { font-size: 10px; color: #6B6977; margin-top: 2px; }

        /* The numeral spec row: figures between hairlines under an ink rule (book §2.5). */
        .strip { margin-top: 18px; border-top: 1.5px solid #181723; }
        .strip td { padding: 10px 14px 12px; border-left: 1px solid #E1DDD0; width: 25%; }
        .strip td.first { border-left: none; padding-left: 0; }
        .strip .figure { font-size: 26px; line-height: 1.05; margin-top: 4px; letter-spacing: -0.4px; }
        .strip .cap { font-size: 9px; color: #6B6977; margin-top: 3px; }

        .hero { margin-top: 20px; background-color: #181723; color: #F5F2E8; border-radius: 12px; padding: 16px 20px; }
        .hero .eyebrow { color: #AEACB5; }
        .hero .figure { font-size: 34px; line-height: 1; margin-top: 6px; color: #F5F2E8; }
        .hero .cap { color: #AEACB5; font-size: 10px; margin-top: 4px; }

        .rows { margin-top: 18px; }
        .rows th { text-align: left; padding: 0 8px 8px; border-bottom: 1px solid #181723; }
        .rows th.right { text-align: right; }
        .rows th:first-child, .rows td:first-child { padding-left: 0; }
        .rows th:last-child, .rows td:last-child { padding-right: 0; }
        .rows td { padding: 6px 8px; border-bottom: 1px solid #E1DDD0; font-size: 10px; line-height: 1.35; }
        .rows td.num { text-align: right; white-space: nowrap; font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }
        .rows .idx { color: #AEACB5; font-size: 9px; width: 24px; font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }

        .pill { display: inline-block; padding: 2px 8px 2px 7px; border-radius: 9px; font-size: 8.5px; font-weight: bold; }
        .pill .dot { display: inline-block; width: 5px; height: 5px; border-radius: 2.5px; margin-right: 4px; }

        .card { margin-top: 16px; border: 1px solid #E1DDD0; border-radius: 12px; background-color: #FBFAF6; padding: 12px 16px; }
        .card .body { margin-top: 5px; font-size: 10px; }

        .chart { margin-top: 18px; }
        .chart img { width: 100%; }
        .legend { margin-top: 6px; }
        .swatch { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin: 0 4px 0 12px; }

        .footer {
            position: fixed;
            left: 52px;
            right: 52px;
            top: 1034px;
            height: 30px;
            border-top: 1px solid #E1DDD0;
            padding-top: 10px;
        }
        .footer .wordmark { height: 12px; vertical-align: middle; }
    </style>
</head>
<body>

    <div class="footer">
        <table>
            <tr>
                <td class="eyebrow" style="vertical-align: middle;">{{ $org['name'] }} &nbsp;&middot;&nbsp; Marketing brighter together.</td>
                <td class="right" style="vertical-align: middle;">
                    <span class="eyebrow" style="letter-spacing: 1px;">Prepared with</span>
                    <img class="wordmark" src="{{ $wordmark }}" alt="Alima">
                </td>
            </tr>
        </table>
    </div>

    {{-- Cover --}}
    <div class="band">
        <table>
            <tr>
                <td style="vertical-align: middle;">
                    <table style="width: auto;">
                        <tr>
                            <td style="vertical-align: middle;"><img class="logo" src="{{ $logo }}" alt=""></td>
                            <td style="vertical-align: middle;">
                                <div class="serif studio">{{ $org['name'] }}</div>
                                <div class="mono caption">ANALYTICS REPORT</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="right" style="vertical-align: middle;">
                    <div class="eyebrow">Range</div>
                    <div class="mono range">{{ $rangeLabel }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <span class="eyebrow"><span class="dash"></span>Studio review &mdash; {{ $summary['client']['name'] ?? 'Every client' }}</span>
        <div class="serif cover-title">{{ Str::beforeLast($title, ' ') !== $title ? Str::beforeLast($title, ' ').' ' : '' }}<em>{{ Str::afterLast($title, ' ') }}</em>.</div>
        <div class="lead">{{ $description ?: 'How the studio is tracking — output, approvals, billing and AI — in '.$org['name'].'’s own timezone.' }}</div>

        <table class="strip">
            <tr>
                <td class="first">
                    <div class="eyebrow">Range</div>
                    <div class="serif figure" style="font-size: 15px;">{{ $rangeLabel }}</div>
                    <div class="cap mono">{{ $filters['timezone'] }} · per {{ $filters['granularity'] }}</div>
                </td>
                @if ($has('posts'))
                    <td>
                        <div class="eyebrow">Published</div>
                        <div class="serif figure">{{ $int($summary['posts']['published']) }}</div>
                        <div class="cap">{{ $summary['posts']['success_rate'] === null ? 'No publish attempts' : $percent($summary['posts']['success_rate']).' success' }}</div>
                    </td>
                @endif
                @if ($has('approvals'))
                    <td>
                        <div class="eyebrow">Client turnaround</div>
                        <div class="serif figure">{{ $hours($summary['approvals']['client_turnaround']['median_hours']) }}</div>
                        <div class="cap">median · {{ $int($summary['approvals']['client_turnaround']['decisions']) }} decisions</div>
                    </td>
                @endif
                @if ($has('revenue'))
                    <td>
                        <div class="eyebrow">Invoiced · {{ $currency }}</div>
                        <div class="serif figure">{{ $money(collect($summary['revenue']['invoiced'])->firstWhere('currency', $currency)['total'] ?? 0) }}</div>
                        <div class="cap">{{ $int($summary['revenue']['invoices_issued']) }} invoices issued</div>
                    </td>
                @endif
            </tr>
        </table>

        @if ($chart)
            <div class="chart">
                <div class="eyebrow">Activity per {{ $filters['granularity'] }}</div>
                <img src="{{ $chart }}" alt="Posts created, published and failed per {{ $filters['granularity'] }}">
                <table class="legend">
                    <tr>
                        <td class="mono muted" style="font-size: 8.5px;">{{ $firstLabel }}</td>
                        <td class="right eyebrow">
                            <span class="swatch" style="background-color: #205557;"></span>Published
                            <span class="swatch" style="background-color: #B52A1B;"></span>Failed
                            <span class="swatch" style="background-color: #4E91D4; opacity: 0.35;"></span>Created
                        </td>
                        <td class="right mono muted" style="font-size: 8.5px; width: 70px;">{{ $lastLabel }}</td>
                    </tr>
                </table>
            </div>
        @endif

        <div class="card">
            <div class="eyebrow">In this report</div>
            <div class="body">
                {{ collect($summary['metrics'])->map(fn ($metric) => ['clients' => 'Clients', 'posts' => 'Publishing', 'approvals' => 'Approvals', 'team' => 'Team workload', 'revenue' => 'Billing', 'ai' => 'AI usage'][$metric] ?? $metric)->implode(' · ') }}
            </div>
            <div class="body muted">Generated {{ $generatedAt->format('j M Y, H:i') }}. Internal review document — figures are live counts, not stored totals.</div>
        </div>
    </div>

    {{-- Clients --}}
    @if ($has('clients'))
        @php $clients = $summary['clients']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">01 &mdash; Clients</span>
            <div class="serif headline">{{ $sectionTitles['clients'][0] }} <em>{{ $sectionTitles['clients'][1] }}</em>.</div>
            <div class="note">Health is a right-now snapshot; “new” counts clients added in the range.</div>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Clients</div><div class="serif figure">{{ $int($clients['total']) }}</div><div class="cap">+{{ $int($clients['new']) }} new in range</div></td>
                    <td><div class="eyebrow">Active</div><div class="serif figure">{{ $int($clients['active']) }}</div><div class="cap">{{ $int($clients['paused']) }} paused · {{ $int($clients['offboarding']) }} offboarding</div></td>
                    <td><div class="eyebrow">Green</div><div class="serif figure">{{ $int($clients['health']['green']) }}</div><div class="cap">in good standing</div></td>
                    <td><div class="eyebrow">Needs a look</div><div class="serif figure">{{ $int($clients['health']['red'] + $clients['health']['amber']) }}</div><div class="cap">{{ $int($clients['health']['red']) }} red · {{ $int($clients['health']['amber']) }} amber</div></td>
                </tr>
            </table>

            @if ($clients['attention'] !== [])
                <table class="rows">
                    <thead><tr><th class="eyebrow">No.</th><th class="eyebrow">Client</th><th class="eyebrow">Health</th><th class="eyebrow">Why</th></tr></thead>
                    <tbody>
                        @foreach ($clients['attention'] as $index => $client)
                            @php $tone = $tones[$client['health']] ?? $tones['amber']; @endphp
                            <tr>
                                <td class="idx">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $client['name'] }}</td>
                                <td><span class="pill" style="background-color: {{ $tone['bg'] }}; color: {{ $tone['fg'] }};"><span class="dot" style="background-color: {{ $tone['fg'] }};"></span>{{ $tone['label'] }}</span></td>
                                <td class="muted">{{ $client['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="card"><div class="body">Every client is in good standing. Nice work.</div></div>
            @endif

            @if (is_array($summary['top_clients'] ?? null) && $summary['top_clients'] !== [])
                <table class="rows">
                    <thead>
                        <tr>
                            <th class="eyebrow">No.</th><th class="eyebrow">Busiest clients</th><th class="eyebrow">Health</th>
                            <th class="eyebrow right">Published</th><th class="eyebrow right">Scheduled</th><th class="eyebrow right">Waiting</th>
                            @if ($has('revenue'))<th class="eyebrow right">Invoiced · {{ $currency }}</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary['top_clients'] as $index => $client)
                            @php $tone = $tones[$client['health']] ?? $tones['green']; @endphp
                            <tr>
                                <td class="idx">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $client['name'] }}</td>
                                <td><span class="pill" style="background-color: {{ $tone['bg'] }}; color: {{ $tone['fg'] }};"><span class="dot" style="background-color: {{ $tone['fg'] }};"></span>{{ $tone['label'] }}</span></td>
                                <td class="num">{{ $int($client['published']) }}</td>
                                <td class="num">{{ $int($client['scheduled']) }}</td>
                                <td class="num">{{ $int($client['waiting_on_client']) }}</td>
                                @if ($has('revenue'))<td class="num">{{ $money($client['invoiced'] ?? 0) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- Publishing --}}
    @if ($has('posts'))
        @php $posts = $summary['posts']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">02 &mdash; Publishing</span>
            <div class="serif headline">{{ $sectionTitles['posts'][0] }} <em>{{ $sectionTitles['posts'][1] }}</em>.</div>
            <div class="note">Published and failed are counted the moment each post changed status.</div>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Created</div><div class="serif figure">{{ $int($posts['created']) }}</div><div class="cap">new posts in range</div></td>
                    <td><div class="eyebrow">Published</div><div class="serif figure">{{ $int($posts['published']) }}</div><div class="cap">{{ $percent($posts['success_rate']) }} success</div></td>
                    <td><div class="eyebrow">Failed</div><div class="serif figure">{{ $int($posts['failed']) }}</div><div class="cap">publish attempts</div></td>
                    <td><div class="eyebrow">Scheduled now</div><div class="serif figure">{{ $int($posts['scheduled']) }}</div><div class="cap">queued to go out</div></td>
                </tr>
            </table>

            <table style="margin-top: 4px;">
                <tr>
                    <td style="width: 48%; padding-right: 4%;">
                        <table class="rows">
                            <thead><tr><th class="eyebrow">Pipeline right now</th><th class="eyebrow right">Posts</th></tr></thead>
                            <tbody>
                                @foreach ($posts['pipeline'] as $row)
                                    @continue($row['count'] === 0)
                                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $int($row['count']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                    <td style="width: 48%;">
                        <table class="rows">
                            <thead><tr><th class="eyebrow">Published by platform</th><th class="eyebrow right">Posts</th></tr></thead>
                            <tbody>
                                @foreach ($posts['by_platform'] as $row)
                                    <tr><td>{{ $row['label'] }}</td><td class="num">{{ $int($row['published']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
            </table>

            @if ($posts['top_campaigns'] !== [])
                <table class="rows">
                    <thead><tr><th class="eyebrow">No.</th><th class="eyebrow">Top campaigns</th><th class="eyebrow">Client</th><th class="eyebrow right">Published</th><th class="eyebrow right">Scheduled</th></tr></thead>
                    <tbody>
                        @foreach ($posts['top_campaigns'] as $index => $campaign)
                            <tr>
                                <td class="idx">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $campaign['name'] }}</td>
                                <td class="muted">{{ $campaign['client_name'] }}</td>
                                <td class="num">{{ $int($campaign['published']) }}</td>
                                <td class="num">{{ $int($campaign['scheduled']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- Approvals --}}
    @if ($has('approvals'))
        @php $approvals = $summary['approvals']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">03 &mdash; Approvals</span>
            <div class="serif headline">{{ $sectionTitles['approvals'][0] }} <em>{{ $sectionTitles['approvals'][1] }}</em>.</div>
            <div class="note">Turnaround runs from a post entering review to the next decision.</div>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Sent to client</div><div class="serif figure">{{ $int($approvals['sent_to_client']) }}</div><div class="cap">{{ $int($approvals['approved']) }} approved · {{ $int($approvals['changes_requested']) }} changes</div></td>
                    <td><div class="eyebrow">First-time approval</div><div class="serif figure">{{ $percent($approvals['first_time_approval_rate']) }}</div><div class="cap">approved with no changes</div></td>
                    <td><div class="eyebrow">Client · median</div><div class="serif figure">{{ $hours($approvals['client_turnaround']['median_hours']) }}</div><div class="cap">p90 {{ $hours($approvals['client_turnaround']['p90_hours']) }}</div></td>
                    <td><div class="eyebrow">Internal · median</div><div class="serif figure">{{ $hours($approvals['internal_turnaround']['median_hours']) }}</div><div class="cap">p90 {{ $hours($approvals['internal_turnaround']['p90_hours']) }}</div></td>
                </tr>
            </table>

            <div class="hero">
                <div class="eyebrow">Waiting on clients right now</div>
                <div class="serif figure">{{ $int($approvals['waiting_now']) }}</div>
                <div class="cap">
                    @if ($approvals['oldest_waiting'])
                        Oldest: {{ $approvals['oldest_waiting']['client_name'] }} — waiting {{ $hours($approvals['oldest_waiting']['hours_waiting']) }}.
                    @elseif ($approvals['waiting_now'] === 0)
                        Nothing is waiting on a client. All clear.
                    @else
                        Sent before send dates were logged, so the wait can't be timed.
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Team --}}
    @if ($has('team'))
        @php $team = $summary['team']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">04 &mdash; Team workload</span>
            <div class="serif headline">{{ $sectionTitles['team'][0] }} <em>{{ $sectionTitles['team'][1] }}</em>.</div>
            <div class="note">Throughput for planning, sorted by name — not a leaderboard.</div>

            <table class="rows">
                <thead><tr><th class="eyebrow">Team member</th><th class="eyebrow">Role</th><th class="eyebrow right">Posts created</th><th class="eyebrow right">Status changes</th><th class="eyebrow right">Sent to client</th><th class="eyebrow right">Scheduled</th></tr></thead>
                <tbody>
                    @foreach ($team['members'] as $member)
                        <tr>
                            <td>{{ $member['name'] }}</td>
                            <td class="muted">{{ $member['role_label'] }}</td>
                            <td class="num">{{ $int($member['posts_created']) }}</td>
                            <td class="num">{{ $int($member['transitions']) }}</td>
                            <td class="num">{{ $int($member['sent_to_client']) }}</td>
                            <td class="num">{{ $int($member['scheduled']) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="muted">Client decisions</td>
                        <td class="muted">Review links & portal</td>
                        <td class="num">—</td>
                        <td class="num">{{ $int($team['client_decisions']) }}</td>
                        <td class="num">—</td>
                        <td class="num">—</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    {{-- Billing --}}
    @if ($has('revenue'))
        @php $revenue = $summary['revenue']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">05 &mdash; Billing</span>
            <div class="serif headline">{{ $sectionTitles['revenue'][0] }} <em>{{ $sectionTitles['revenue'][1] }}</em>.</div>
            <div class="note">Sent and paid invoices only. Currencies are never added together.</div>

            <table class="rows">
                <thead><tr><th class="eyebrow">Figure</th><th class="eyebrow right">Per currency</th></tr></thead>
                <tbody>
                    <tr><td>Invoiced in range</td><td class="num">{{ $moneyList($revenue['invoiced']) }}</td></tr>
                    <tr><td>Collected in range</td><td class="num">{{ $moneyList($revenue['collected']) }}</td></tr>
                    <tr><td>Outstanding now</td><td class="num">{{ $moneyList($revenue['outstanding']) }}</td></tr>
                    <tr><td>Overdue now · {{ $int($revenue['overdue_count']) }} invoices</td><td class="num">{{ $moneyList($revenue['overdue']) }}</td></tr>
                    <tr><td>30-day run rate</td><td class="num">{{ $moneyList($revenue['monthly_run_rate']) }}</td></tr>
                    <tr><td>Active retainers · {{ $int($revenue['retainers']['clients']) }} clients, monthly</td><td class="num">{{ $moneyList($revenue['retainers']['monthly']) }}</td></tr>
                </tbody>
            </table>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Invoices issued</div><div class="serif figure">{{ $int($revenue['invoices_issued']) }}</div></td>
                    <td><div class="eyebrow">Invoices paid</div><div class="serif figure">{{ $int($revenue['invoices_paid']) }}</div></td>
                    <td><div class="eyebrow">Days to pay</div><div class="serif figure">{{ $revenue['average_days_to_pay'] ?? '—' }}</div><div class="cap">average, sent → paid</div></td>
                    <td></td>
                </tr>
            </table>
        </div>
    @endif

    {{-- AI --}}
    @if ($has('ai'))
        @php $ai = $summary['ai']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">06 &mdash; AI usage</span>
            <div class="serif headline">{{ $sectionTitles['ai'][0] }} <em>{{ $sectionTitles['ai'][1] }}</em>.</div>
            <div class="note">AI drafts and suggests; people choose, approve and publish.</div>

            <table class="rows">
                <thead><tr><th class="eyebrow">Kind</th><th class="eyebrow right">Generated</th><th class="eyebrow right">Failed</th></tr></thead>
                <tbody>
                    @foreach ($ai['by_method'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="num">{{ $int($row['succeeded']) }}</td><td class="num">{{ $int($row['failed']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Generated</div><div class="serif figure">{{ $int($ai['generated']) }}</div></td>
                    <td>
                        <div class="eyebrow">Images this month</div>
                        <div class="serif figure">{{ $int($ai['images_this_month']['used']) }}</div>
                        <div class="cap">{{ $ai['images_this_month']['quota'] === null ? 'No monthly limit' : 'of '.$int($ai['images_this_month']['quota']).' allowed' }}</div>
                    </td>
                    @if ($ai['cost_estimate'] !== null)
                        <td><div class="eyebrow">Estimated cost</div><div class="serif figure">{{ MoneyFormatter::format((float) $ai['cost_estimate'], 'USD') }}</div><div class="cap">vendor estimate, USD</div></td>
                    @else
                        <td></td>
                    @endif
                    <td></td>
                </tr>
            </table>
        </div>
    @endif

    {{-- Engagement (Step M-3.1) --}}
    @if ($has('engagement'))
        @php $engagement = $summary['engagement']; @endphp
        <div class="sheet page-break">
            <span class="eyebrow">07 &mdash; Engagement</span>
            <div class="serif headline">{{ $sectionTitles['engagement'][0] }} <em>{{ $sectionTitles['engagement'][1] }}</em>.</div>
            <div class="note">Latest platform readings for posts published in range. A dash means the platform didn't report it.</div>

            <table class="strip">
                <tr>
                    <td class="first"><div class="eyebrow">Reach</div><div class="serif figure">{{ $count($engagement['totals']['reach']) }}</div><div class="cap">{{ $int($engagement['measured_channels']) }} of {{ $int($engagement['published_channels']) }} channels measured</div></td>
                    <td><div class="eyebrow">Interactions</div><div class="serif figure">{{ $count($engagement['totals']['engagement']) }}</div><div class="cap">likes, comments, shares, saves</div></td>
                    <td><div class="eyebrow">Engagement rate</div><div class="serif figure">{{ $percent($engagement['engagement_rate']) }}</div><div class="cap">interactions ÷ reach</div></td>
                    <td><div class="eyebrow">Followers</div><div class="serif figure">{{ $count($engagement['followers']['current']) }}</div><div class="cap">{{ $engagement['followers']['growth'] === null ? 'growth not tracked yet' : ($engagement['followers']['growth'] >= 0 ? '+' : '').$int($engagement['followers']['growth']).' in range' }}</div></td>
                </tr>
            </table>

            <table class="rows">
                <thead><tr><th class="eyebrow">Platform</th><th class="eyebrow right">Published</th><th class="eyebrow right">Interactions</th><th class="eyebrow right">Rate</th></tr></thead>
                <tbody>
                    @foreach ($engagement['by_platform'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}@if (! $row['available'])<span class="muted"> &mdash; not available</span>@endif</td>
                            <td class="num">{{ $int($row['published']) }}</td>
                            <td class="num">{{ $count($row['engagement']) }}</td>
                            <td class="num">{{ $percent($row['engagement_rate']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($engagement['top_posts'] !== [])
                <table class="rows">
                    <thead><tr><th class="eyebrow">No.</th><th class="eyebrow">Top posts</th><th class="eyebrow right">Interactions</th><th class="eyebrow right">Reach</th></tr></thead>
                    <tbody>
                        @foreach ($engagement['top_posts'] as $index => $post)
                            <tr>
                                <td class="idx">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $post['caption'] ?? 'Untitled post' }} <span class="muted">{{ $post['client_name'] }}</span></td>
                                <td class="num">{{ $count($post['engagement']) }}</td>
                                <td class="num">{{ $count($post['reach'] ?? $post['impressions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</body>
</html>
