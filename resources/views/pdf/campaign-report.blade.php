@php
    /*
     * Campaign wrap report (Step C-7.3) — the analytics report's "Studio Paper" for dompdf: cream
     * paper, Ink band with the master mark on a Deep Teal tile, Poppins headline with one upright
     * teal accent word, DM Mono spec labels, the one orange dash. KPI figures come only from the
     * report's frozen snapshot. Never fees or invoices. Misses are shown honestly, with a label.
     */
    use App\Support\Pdf\PdfBrand;

    $logo = PdfBrand::markTile();
    $wordmark = PdfBrand::wordmark();
    $dates = collect([$campaign->start_date?->format('j M Y'), $campaign->end_date?->format('j M Y')])->filter()->implode(' → ');
    $format = function (array $kpi, $value) {
        if ($value === null) {
            return '—';
        }

        return match ($kpi['unit'] ?? 'count') {
            'percent' => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%',
            'hours' => ((float) $value >= 48 ? round((float) $value / 24, 1).' days' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').' h'),
            default => number_format((float) $value),
        };
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $campaign->name }} — Wrap report</title>
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
        .lead { font-size: 13px; color: #4A4857; margin-top: 12px; line-height: 1.5; }
        .strip { margin-top: 22px; border-top: 1.5px solid #181723; }
        .strip td { padding: 10px 14px 12px; border-left: 1px solid #E1DDD0; }
        .strip td.first { border-left: none; padding-left: 0; }
        .strip .figure { font-size: 24px; line-height: 1.05; margin-top: 4px; }
        .strip .cap { font-size: 9px; color: #6B6977; margin-top: 3px; }
        .rows { margin-top: 18px; }
        .rows th { text-align: left; padding: 0 8px 8px; border-bottom: 1px solid #181723; }
        .rows th:first-child, .rows td:first-child { padding-left: 0; }
        .rows td { padding: 7px 8px; border-bottom: 1px solid #E1DDD0; font-size: 10px; }
        .rows td.num { text-align: right; font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; white-space: nowrap; }
        .pill { display: inline-block; padding: 2px 8px 2px 7px; border-radius: 9px; font-size: 8.5px; font-weight: bold; }
        .pill .dot { display: inline-block; width: 5px; height: 5px; border-radius: 2.5px; margin-right: 4px; }
        .section { margin-top: 22px; }
        .section h2 { font-size: 15px; margin: 4px 0 6px; }
        .section p { margin: 0; font-size: 11px; white-space: pre-line; }
        .section li { margin-bottom: 4px; font-size: 11px; }
        .footer { position: fixed; left: 52px; right: 52px; top: 1034px; height: 30px; border-top: 1px solid #E1DDD0; padding-top: 10px; }
        .footer .wordmark { height: 12px; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="footer">
        <table>
            <tr>
                <td class="eyebrow" style="vertical-align: middle;">{{ $organization->name }} &nbsp;&middot;&nbsp; Marketing brighter together.</td>
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
                                <div class="serif studio">{{ $organization->name }}</div>
                                <div class="mono caption">WRAP REPORT{{ $campaign->code ? ' · '.$campaign->code : '' }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="right" style="vertical-align: middle;">
                    <div class="eyebrow">For</div>
                    <div class="range">{{ $client->name }}</div>
                    @if ($dates !== '')
                        <div class="mono range" style="font-size: 10px;">{{ $dates }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <span class="eyebrow"><span class="dash"></span>Wrap report &mdash; {{ $campaign->name }}</span>
        <div class="serif title">How it <em>went</em>.</div>
        @if ($report->summary)
            <div class="lead">{{ $report->summary }}</div>
        @endif

        <table class="strip">
            <tr>
                <td class="first" style="width: 33%;">
                    <div class="eyebrow">Published</div>
                    <div class="serif figure">{{ number_format($published) }}</div>
                    <div class="cap">posts went live</div>
                </td>
                <td style="width: 33%;">
                    <div class="eyebrow">Targets met</div>
                    <div class="serif figure">{{ collect($kpis)->where('met', true)->count() }} / {{ collect($kpis)->where('available', true)->count() }}</div>
                    <div class="cap">of the targets we track</div>
                </td>
                <td style="width: 33%;">
                    <div class="eyebrow">Shared</div>
                    <div class="serif figure" style="font-size: 15px;">{{ $report->shared_at?->format('j M Y') ?? 'Draft' }}</div>
                    <div class="cap">version {{ max(1, $report->version) }}</div>
                </td>
            </tr>
        </table>

        @if ($kpis !== [])
            <table class="rows">
                <thead><tr><th class="eyebrow">Target</th><th class="eyebrow right">Aim</th><th class="eyebrow right">Result</th><th class="eyebrow right">Status</th></tr></thead>
                <tbody>
                    @foreach ($kpis as $kpi)
                        @php
                            $tone = ! ($kpi['available'] ?? false)
                                ? ['bg' => '#EDEBE3', 'fg' => '#4A4857', 'label' => 'Not tracked yet']
                                : (($kpi['met'] ?? false) ? ['bg' => '#E2ECE3', 'fg' => '#276B43', 'label' => 'Hit'] : ['bg' => '#F2E9D6', 'fg' => '#8A5A00', 'label' => 'Missed']);
                        @endphp
                        <tr>
                            <td>{{ $kpi['label'] }}</td>
                            <td class="num">{{ $format($kpi, $kpi['target']) }}</td>
                            <td class="num">{{ ($kpi['available'] ?? false) ? $format($kpi, $kpi['actual']) : '—' }}</td>
                            <td class="right"><span class="pill" style="background-color: {{ $tone['bg'] }}; color: {{ $tone['fg'] }};"><span class="dot" style="background-color: {{ $tone['fg'] }};"></span>{{ $tone['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (! empty($report->highlights))
            <div class="section">
                <span class="eyebrow">A &mdash; Highlights</span>
                <ul>
                    @foreach ($report->highlights as $highlight)
                        <li>{{ $highlight }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($report->learnings)
            <div class="section">
                <span class="eyebrow">B &mdash; What we learned</span>
                <p>{{ $report->learnings }}</p>
            </div>
        @endif

        @if ($report->next_steps)
            <div class="section">
                <span class="eyebrow">C &mdash; What's next</span>
                <p>{{ $report->next_steps }}</p>
            </div>
        @endif

        <div class="section">
            <p class="muted" style="font-size: 9px;">Figures frozen when this report was shared on {{ $report->shared_at?->format('j M Y') ?? $generatedAt->format('j M Y') }}.</p>
        </div>
    </div>
</body>
</html>
