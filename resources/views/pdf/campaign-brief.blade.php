@php
    /*
     * Campaign brief (Step C-2.3) — the invoice/analytics "Studio Paper" language for dompdf: Cloud
     * Cream paper, a Midnight Ink band with the master mark on a Deep Teal tile, Poppins SemiBold
     * headline with one upright teal accent word, DM Mono spec labels and ruled "A — Background"
     * rows. Signal Orange appears once, as the cover's rounded dash. An approved brief carries the
     * approval stamp and the content hash of the exact revision the client signed.
     */
    use App\Enums\BriefStatus;
    use App\Support\Pdf\PdfBrand;
    use Illuminate\Support\Str;

    $logo = PdfBrand::markTile();
    $wordmark = PdfBrand::wordmark();

    $sections = [
        'background' => 'Background',
        'objective_detail' => 'Objective',
        'audience' => 'Audience',
        'key_messages' => 'Key messages',
        'offer' => 'Offer',
        'call_to_action' => 'Call to action',
        'mandatories' => 'Mandatories',
        'avoid' => 'Avoid',
        'deliverables_summary' => 'Deliverables',
        'success_measures' => 'Success measures',
    ];

    $letters = range('A', 'Z');
    $dates = collect([$campaign->start_date?->format('j M Y'), $campaign->end_date?->format('j M Y')])->filter()->implode(' → ');
    $nameWords = explode(' ', $campaign->name);
    $lastWord = array_pop($nameWords);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $campaign->name }} — Campaign brief</title>
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
        .lead { font-size: 13px; color: #4A4857; margin-top: 10px; line-height: 1.45; }

        .stamp { margin-top: 18px; border-radius: 12px; padding: 12px 16px; }
        .stamp.approved { background-color: #E2ECE3; color: #276B43; }
        .stamp.draft { background-color: #EDEBE3; color: #4A4857; }
        .stamp .label { font-size: 12px; font-weight: bold; }
        .stamp .hash { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 7.5px; margin-top: 3px; word-break: break-all; }

        .spec { margin-top: 20px; border-top: 1.5px solid #181723; }
        .spec td { padding: 12px 0; border-bottom: 1px solid #E1DDD0; }
        .spec td.label { width: 150px; padding-right: 18px; }
        .spec .body { font-size: 11px; white-space: pre-line; }
        .spec ul { margin: 0; padding-left: 14px; }
        .spec li { margin-bottom: 3px; }

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
                                <div class="mono caption">CAMPAIGN BRIEF{{ $campaign->code ? ' · '.$campaign->code : '' }}</div>
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
        <span class="eyebrow"><span class="dash"></span>Campaign brief &mdash; {{ $client->name }}</span>
        <div class="serif title">{{ $nameWords !== [] ? implode(' ', $nameWords).' ' : '' }}<em>{{ $lastWord }}</em>.</div>
        @if ($campaign->goal)
            <div class="lead">{{ $campaign->goal }}</div>
        @endif

        @if ($approved)
            <div class="stamp approved">
                <div class="label">Approved by {{ $approved->decider?->name ?? 'the client' }} on {{ $approved->decided_at?->setTimezone($timezone)->format('j M Y, H:i') }}, revision {{ $approved->number }}.</div>
                <div class="hash">SHA-256 {{ $approved->content_hash }}</div>
            </div>
        @else
            <div class="stamp draft">
                <div class="label">{{ $status === BriefStatus::Sent ? 'With the client for sign-off.' : 'Working draft. Not yet approved.' }}</div>
                <div class="hash">Generated {{ $generatedAt->format('j M Y, H:i') }}</div>
            </div>
        @endif

        <table class="spec">
            @php $index = 0; @endphp
            @foreach ($sections as $field => $label)
                @php $value = $content[$field] ?? null; @endphp
                @continue(is_array($value) ? $value === [] : blank($value))
                <tr>
                    <td class="label"><span class="eyebrow">{{ $letters[$index++] }} &mdash; {{ $label }}</span></td>
                    <td>
                        @if (is_array($value))
                            <ul class="body">
                                @foreach ($value as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @else
                            <div class="body">{{ Str::of((string) $value)->trim() }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</body>
</html>
