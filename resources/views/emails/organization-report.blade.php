@php
    /*
     * Step 17.8 — a scheduled analytics report. Headline figures only (the full run is attached as
     * CSV + PDF). Only sections present in `$summary` are shown: it was built for this recipient.
     */
    use App\Support\MoneyFormatter;
    use Carbon\CarbonImmutable;

    $filters = $summary['filters'];
    $currency = $summary['primary_currency'];
    $range = CarbonImmutable::parse($filters['from'])->format('j M').' → '.CarbonImmutable::parse($filters['to'])->format('j M Y');
    $hours = fn ($value) => $value === null ? '—' : ($value >= 48 ? round($value / 24, 1).' days' : $value.' h');

    $figures = [];

    if (is_array($summary['posts'] ?? null)) {
        $figures[] = ['Published', number_format($summary['posts']['published']), $summary['posts']['success_rate'] === null ? 'No publish attempts yet' : $summary['posts']['success_rate'].'% went out first time'];
    }

    if (is_array($summary['approvals'] ?? null)) {
        $figures[] = ['Client turnaround', $hours($summary['approvals']['client_turnaround']['median_hours']), number_format($summary['approvals']['waiting_now']).' waiting on clients now'];
    }

    if (is_array($summary['clients'] ?? null)) {
        $needsLook = $summary['clients']['health']['red'] + $summary['clients']['health']['amber'];
        $figures[] = ['Clients', number_format($summary['clients']['total']), $needsLook === 0 ? 'Every client in good standing' : $needsLook.' need a look'];
    }

    if (is_array($summary['revenue'] ?? null)) {
        $invoiced = collect($summary['revenue']['invoiced'])->firstWhere('currency', $currency)['total'] ?? 0;
        $figures[] = ['Invoiced · '.$currency, MoneyFormatter::format((float) $invoiced, $currency), number_format($summary['revenue']['invoices_issued']).' invoices issued'];
    }

    if (is_array($summary['ai'] ?? null)) {
        $figures[] = ['AI drafts', number_format($summary['ai']['generated']), 'Trends, ideas, briefs and images'];
    }

    $published = $summary['posts']['published'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reportName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:'Poppins', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:12px; overflow:hidden; border:1px solid #E1DDD0;">
                    <tr>
                        <td style="background-color:#205557; padding:24px 32px;">
                            <img src="{{ asset('brand/alima_logo_cream@2x.png') }}" width="120" height="36" alt="Alima" style="display:block; border:0; width:120px; height:36px;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px; font-family:'DM Mono', 'Courier New', monospace; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; color:#6B6977;">
                                {{ $reportName }} &middot; {{ $range }}
                            </p>
                            <h1 style="margin:0 0 12px; font-family:'Poppins', Arial, sans-serif; font-size:22px; line-height:1.3; color:#181723; font-weight:600;">
                                Hi {{ $recipientName }} — {{ $published === null ? 'here’s how '.$organizationName.' is tracking.' : ($published > 0 ? 'here’s what went out.' : 'a quiet stretch, here’s where things stand.') }}
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Your saved report, freshly run in {{ $filters['timezone'] }} time. The full figures are attached as a CSV and a PDF.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; border-top:2px solid #181723;">
                                @foreach ($figures as [$label, $value, $caption])
                                    <tr>
                                        <td style="padding:12px 0; border-bottom:1px solid #E1DDD0;">
                                            <p style="margin:0; font-family:'DM Mono', 'Courier New', monospace; font-size:11px; letter-spacing:1px; text-transform:uppercase; color:#6B6977;">{{ $label }}</p>
                                            <p style="margin:2px 0 0; font-size:13px; color:#4A4857;">{{ $caption }}</p>
                                        </td>
                                        <td align="right" style="padding:12px 0; border-bottom:1px solid #E1DDD0; font-size:22px; font-weight:700; color:#181723; white-space:nowrap;">
                                            {{ $value }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color:#181723; border-radius:999px;">
                                        <a href="{{ $reportUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#F5F2E8; text-decoration:none;">See the full report</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. Marketing brighter together. You get this because you're on this report's schedule — change it from the report in Alima.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
