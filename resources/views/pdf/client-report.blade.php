@php
    /*
     * Monthly client report (Step M-3.3) — the analytics report's "Studio Paper" for dompdf: cream
     * paper, Ink band with the master mark on a Deep Teal tile, Poppins headline with one upright
     * teal accent word, DM Mono spec labels, the one orange dash. Drawn only from the report's
     * snapshot and the agency's commentary: no billing data, ever. A dash means a platform didn't
     * report that figure — never a zero.
     */
    use App\Support\Pdf\PdfBrand;

    $logo = PdfBrand::markTile();
    $wordmark = PdfBrand::wordmark();
    $period = $snapshot['period']['label'] ?? $report->periodLabel();
    $client = $snapshot['client']['name'] ?? ($report->client->name ?? 'Client');
    $studio = $snapshot['studio']['name'] ?? ($report->organization->name ?? '');
    $posts = $snapshot['posts'] ?? [];
    $engagement = $snapshot['engagement'] ?? [];
    $totals = $engagement['totals'] ?? [];
    $followers = $engagement['followers'] ?? [];
    $approvals = $snapshot['approvals'] ?? [];
    $count = fn ($value) => $value === null ? '—' : number_format((int) $value);
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
    $signed = fn ($value) => $value === null ? '—' : (($value > 0 ? '+' : '').number_format((int) $value));
    $hours = function ($value) {
        if ($value === null) {
            return '—';
        }

        return $value >= 48 ? round($value / 24, 1).' days' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').' h';
    };
    $section = 0;
    $letter = function () use (&$section) {
        return chr(65 + $section++);
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $client }} — {{ $period }} report</title>
    <style>
        @include('pdf.partials.brand-fonts')

        @page { margin: 34px 0 78px 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background-color: #F5F2E8; }
        body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.55; color: #181723; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }
        .serif { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-weight: bold; letter-spacing: -0.4px; }
        .mono { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }
        .muted { color: #6B6977; }
        .right { text-align: right; }
        .eyebrow { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 8.5px; letter-spacing: 1.3px; text-transform: uppercase; color: #6B6977; }
        .dash { display: inline-block; width: 14px; height: 3px; border-radius: 2px; background-color: #F03D2A; margin-right: 6px; }
        .band { background-color: #181723; color: #F5F2E8; padding: 56px 52px 22px; margin-top: -34px; }
        .band .logo { width: 34px; height: 34px; }
        .band .studio { font-size: 22px; line-height: 1; color: #F5F2E8; padding-left: 12px; }
        .band .caption { font-size: 8px; letter-spacing: 1.4px; color: #AEACB5; padding-left: 12px; padding-top: 5px; }
        .band .eyebrow { color: #AEACB5; }
        .band .range { font-size: 12px; color: #F5F2E8; padding-top: 3px; }
        .sheet { padding: 26px 52px 0; }
        .title { font-size: 40px; line-height: 1.04; letter-spacing: -1px; margin: 14px 0 0; }
        .title em { font-style: normal; color: #205557; }
        .lead { font-size: 12.5px; color: #4A4857; margin-top: 12px; line-height: 1.55; white-space: pre-line; }
        .note { margin-top: 18px; background-color: #ECE2E5; border: 1px solid #DBC3DE; border-radius: 10px; padding: 14px 16px; }
        .note p { margin: 6px 0 0; font-size: 11.5px; white-space: pre-line; }
        .strip { margin-top: 22px; border-top: 1.5px solid #181723; }
        .strip td { padding: 10px 14px 12px; border-left: 1px solid #E1DDD0; }
        .strip td.first { border-left: none; padding-left: 0; }
        .strip .figure { font-size: 24px; line-height: 1.05; margin-top: 4px; }
        .strip .cap { font-size: 9px; color: #6B6977; margin-top: 3px; }
        .rows { margin-top: 14px; }
        .rows th { text-align: left; padding: 0 8px 8px; border-bottom: 1px solid #181723; }
        .rows th:first-child, .rows td:first-child { padding-left: 0; }
        .rows td { padding: 7px 8px; border-bottom: 1px solid #E1DDD0; font-size: 10px; }
        .rows td.num { text-align: right; font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        .section { margin-top: 24px; }
        .section h2 { font-size: 15px; margin: 4px 0 6px; }
        .footer { position: fixed; left: 52px; right: 52px; top: 1034px; height: 30px; border-top: 1px solid #E1DDD0; padding-top: 10px; }
        .footer .wordmark { height: 12px; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="footer">
        <table>
            <tr>
                <td class="eyebrow" style="vertical-align: middle;">{{ $studio }} &nbsp;&middot;&nbsp; Marketing brighter together.</td>
                <td class="right" style="vertical-align: middle;">
                    <span class="eyebrow" style="letter-spacing: 1px;">Prepared with</span>
                    <img class="wordmark" src="{{ $wordmark }}" alt="Alima">
                </td>
            </tr>
        </table>
    </div>

    <div class="band">
        <table>
            <tr>
                <td style="vertical-align: middle;">
                    <table style="width: auto;">
                        <tr>
                            <td style="vertical-align: middle;"><img class="logo" src="{{ $logo }}" alt=""></td>
                            <td style="vertical-align: middle;">
                                <div class="serif studio">{{ $studio }}</div>
                                <div class="mono caption">MONTHLY REPORT</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="right" style="vertical-align: middle;">
                    <div class="eyebrow">For</div>
                    <div class="range">{{ $client }}</div>
                    <div class="mono range" style="font-size: 10px;">{{ $period }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <span class="eyebrow"><span class="dash"></span>Monthly report &mdash; {{ $period }}</span>
        <div class="serif title">Your month in <em>social</em>.</div>

        <table class="strip">
            <tr>
                <td class="first" style="width: 25%;">
                    <div class="eyebrow">Posts published</div>
                    <div class="serif figure">{{ $count($posts['published'] ?? 0) }}</div>
                    <div class="cap">{{ $count($posts['channels'] ?? 0) }} across channels</div>
                </td>
                <td style="width: 25%;">
                    <div class="eyebrow">Reach</div>
                    <div class="serif figure">{{ $count($totals['reach'] ?? null) }}</div>
                    <div class="cap">people reached</div>
                </td>
                <td style="width: 25%;">
                    <div class="eyebrow">Interactions</div>
                    <div class="serif figure">{{ $count($totals['engagement'] ?? null) }}</div>
                    <div class="cap">{{ $percent($engagement['engagement_rate'] ?? null) }} engagement rate</div>
                </td>
                <td style="width: 25%;">
                    <div class="eyebrow">Followers</div>
                    <div class="serif figure">{{ $count($followers['current'] ?? null) }}</div>
                    <div class="cap">{{ $signed($followers['growth'] ?? null) }} this month</div>
                </td>
            </tr>
        </table>

        @if (filled($report->commentary))
            <div class="note">
                <span class="eyebrow">A note from {{ $studio }}</span>
                <p>{{ $report->commentary }}</p>
            </div>
        @endif

        @if (! empty($snapshot['top_posts']))
            <div class="section">
                <span class="eyebrow">{{ $letter() }} &mdash; Top posts</span>
                <table class="rows">
                    <thead><tr><th class="eyebrow">Post</th><th class="eyebrow right">Interactions</th><th class="eyebrow right">Reach</th><th class="eyebrow right">Rate</th></tr></thead>
                    <tbody>
                        @foreach ($snapshot['top_posts'] as $post)
                            <tr>
                                <td>{{ $post['caption'] ?? 'Untitled post' }} <span class="muted mono" style="font-size: 8.5px;">{{ strtoupper(implode(' · ', $post['platforms'] ?? [])) }}</span></td>
                                <td class="num">{{ $count($post['engagement'] ?? null) }}</td>
                                <td class="num">{{ $count($post['reach'] ?? $post['impressions'] ?? null) }}</td>
                                <td class="num">{{ $percent($post['engagement_rate'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! empty($engagement['by_platform']))
            <div class="section">
                <span class="eyebrow">{{ $letter() }} &mdash; By platform</span>
                <table class="rows">
                    <thead><tr><th class="eyebrow">Platform</th><th class="eyebrow right">Posts</th><th class="eyebrow right">Interactions</th><th class="eyebrow right">Rate</th></tr></thead>
                    <tbody>
                        @foreach ($engagement['by_platform'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}@if (! ($row['available'] ?? true))<span class="muted"> &mdash; engagement not available</span>@endif</td>
                                <td class="num">{{ $count($row['published'] ?? 0) }}</td>
                                <td class="num">{{ $count($row['engagement'] ?? null) }}</td>
                                <td class="num">{{ $percent($row['engagement_rate'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! empty($followers['accounts']))
            <div class="section">
                <span class="eyebrow">{{ $letter() }} &mdash; Audience</span>
                <table class="rows">
                    <thead><tr><th class="eyebrow">Account</th><th class="eyebrow right">Followers</th><th class="eyebrow right">This month</th></tr></thead>
                    <tbody>
                        @foreach ($followers['accounts'] as $account)
                            <tr>
                                <td>{{ $account['handle'] }} <span class="muted">{{ $account['platform_label'] }}</span></td>
                                <td class="num">{{ $count($account['followers']) }}</td>
                                <td class="num">{{ $signed($account['growth'] ?? null) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="section">
            <span class="eyebrow">{{ $letter() }} &mdash; Approvals</span>
            <table class="strip" style="margin-top: 8px;">
                <tr>
                    <td class="first" style="width: 33%;"><div class="eyebrow">Sent to you</div><div class="serif figure">{{ $count($approvals['sent_to_client'] ?? 0) }}</div></td>
                    <td style="width: 33%;"><div class="eyebrow">Approved</div><div class="serif figure">{{ $count($approvals['approved'] ?? 0) }}</div><div class="cap">{{ $percent($approvals['first_time_approval_rate'] ?? null) }} first time</div></td>
                    <td style="width: 33%;"><div class="eyebrow">Your turnaround</div><div class="serif figure">{{ $hours($approvals['median_hours'] ?? null) }}</div><div class="cap">median decision time</div></td>
                </tr>
            </table>
        </div>

        @if (! empty($snapshot['campaigns']))
            <div class="section">
                <span class="eyebrow">{{ $letter() }} &mdash; Campaign wrap reports</span>
                @foreach ($snapshot['campaigns'] as $campaign)
                    <h2 class="serif">{{ $campaign['name'] }}</h2>
                    @if ($campaign['summary'])
                        <p style="margin: 0 0 8px; font-size: 11px;">{{ $campaign['summary'] }}</p>
                    @endif
                @endforeach
            </div>
        @endif

        <div class="section">
            <p class="muted" style="font-size: 9px;">Figures for {{ $period }} ({{ $snapshot['period']['timezone'] ?? 'UTC' }}), as the platforms reported them on {{ $report->snapshot_at?->format('j M Y') ?? now()->format('j M Y') }}. A dash means a platform didn't report that figure.</p>
        </div>
    </div>
</body>
</html>
