@php
    /*
     * "Studio Paper" invoice — the portal's visual language translated for dompdf: warm cream paper,
     * an ink header band, Instrument Serif headline with one italic orange accent word, DM Mono
     * spec-sheet labels, hairline tables, and ink (never orange) for the emphasised total.
     *
     * dompdf has no flexbox/grid and no OKLCH, so layout is tables and the brand tokens from
     * resources/css/app.css are inlined as their sRGB hex equivalents.
     */
    use App\Support\MoneyFormatter;

    $money = fn ($amount) => MoneyFormatter::format((float) $amount, $invoice->currency);
    $date = fn ($value) => $value?->format('j M Y');

    $displayStatus = $invoice->displayStatus();
    $tones = [
        'draft' => ['bg' => '#f0ece6', 'fg' => '#4a423b', 'dot' => '#8a8178'],
        'sent' => ['bg' => '#e6eff8', 'fg' => '#1f5f99', 'dot' => '#2a75ba'],
        'overdue' => ['bg' => '#fbe5e3', 'fg' => '#b0191c', 'dot' => '#d81f23'],
        'paid' => ['bg' => '#e2f2e8', 'fg' => '#136b3e', 'dot' => '#1a8a51'],
        'void' => ['bg' => '#f0ece6', 'fg' => '#6a615a', 'dot' => '#aaa39d'],
    ];
    $tone = $tones[$displayStatus] ?? $tones['draft'];

    [$headlineLead, $headlineAccent] = match ($displayStatus) {
        'sent' => ['Payment', 'due'],
        'overdue' => ['Payment', 'overdue'],
        'paid' => ['Paid in', 'full'],
        'void' => ['Invoice', 'void'],
        default => ['Draft', 'invoice'],
    };

    $subline = match ($displayStatus) {
        'sent' => $invoice->due_date
            ? 'Please pay '.$money($invoice->amount).' by '.$date($invoice->due_date).'.'
            : 'Please pay '.$money($invoice->amount).' on receipt.',
        'overdue' => 'This invoice was due on '.$date($invoice->due_date).' — '.$invoice->daysOverdue().' '.\Illuminate\Support\Str::plural('day', $invoice->daysOverdue()).' ago. Please arrange payment as soon as possible.',
        'paid' => 'Received '.$date($invoice->paid_at).'. Thank you — keep this copy as your receipt.',
        'void' => 'This invoice has been cancelled and nothing is owed.'.($invoice->void_reason ? ' '.$invoice->void_reason : ''),
        default => 'A preview of your upcoming invoice — not yet issued.',
    };

    $isSettled = in_array($displayStatus, ['paid', 'void'], true);
    $amountLabel = match ($displayStatus) {
        'paid' => 'Amount paid',
        'void' => 'Amount (void)',
        default => 'Amount due',
    };

    $retainer = (float) ($invoice->retainer_amount_snapshot ?? 0);
    $retainerLabel = $client->billing_cycle ? $client->billing_cycle->label().' retainer' : 'Retainer';
    $hasItems = $items->isNotEmpty();
    $index = 0;

    $logo = 'data:image/svg+xml;base64,'.base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
        .'<rect width="40" height="40" rx="10" fill="#f1561e"/>'
        .'<g transform="translate(8 8) scale(0.75)" fill="#fefdfb">'
        .'<rect x="2" y="2" width="12" height="12" rx="3"/>'
        .'<path d="M18 14V2a12 12 0 0 1 12 12H18Z"/>'
        .'<rect x="2" y="18" width="12" height="12" rx="3"/>'
        .'<rect x="18" y="18" width="12" height="12" rx="6"/>'
        .'</g></svg>'
    );

    $font = fn (string $file) => str_replace('\\', '/', $fontDir.'/'.$file);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @font-face { font-family: 'Instrument Sans'; font-weight: normal; font-style: normal; src: url('{{ $font('InstrumentSans-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Instrument Sans'; font-weight: bold; font-style: normal; src: url('{{ $font('InstrumentSans-SemiBold.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Instrument Serif'; font-weight: normal; font-style: normal; src: url('{{ $font('InstrumentSerif-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Instrument Serif'; font-weight: normal; font-style: italic; src: url('{{ $font('InstrumentSerif-Italic.ttf') }}') format('truetype'); }
        @font-face { font-family: 'DM Mono'; font-weight: normal; font-style: normal; src: url('{{ $font('DMMono-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'DM Mono'; font-weight: bold; font-style: normal; src: url('{{ $font('DMMono-Medium.ttf') }}') format('truetype'); }

        /* Top/bottom margins keep continuation pages off the edges; the header band pulls itself
           back up into the first page's top margin. */
        @page { margin: 34px 0 78px 0; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background-color: #f9f5f0;
        }

        body {
            font-family: 'Instrument Sans', 'DejaVu Sans', sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #1a1511;
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }

        .serif { font-family: 'Instrument Serif', 'DejaVu Serif', serif; font-weight: normal; }
        .mono { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }
        .muted { color: #6a615a; }
        .right { text-align: right; }
        .nums { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; }

        .eyebrow {
            font-family: 'DM Mono', 'DejaVu Sans Mono', monospace;
            font-size: 8.5px;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: #6a615a;
        }

        .square {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 1.5px;
            background-color: #f1561e;
            margin-right: 6px;
        }

        /* Ink header band — the "desk" the paper sheet sits on. */
        .band {
            background-color: #1a1511;
            color: #f5f1ec;
            padding: 56px 52px 22px;
            margin-top: -34px;
        }

        .band .logo { width: 34px; height: 34px; }
        .band .studio { font-size: 22px; line-height: 1; color: #f5f1ec; padding-left: 12px; }
        .band .studio-caption { font-size: 8px; letter-spacing: 1.4px; color: #aaa39d; padding-left: 12px; padding-top: 5px; }
        .band .doc-label { color: #aaa39d; }
        .band .doc-number { font-size: 15px; color: #f5f1ec; letter-spacing: 0.4px; padding-top: 3px; }

        .sheet { padding: 26px 52px 0; }

        .headline {
            font-size: 46px;
            line-height: 0.95;
            letter-spacing: -0.6px;
            color: #1a1511;
            margin: 10px 0 0;
        }

        .headline em { font-style: italic; color: #f1561e; }

        .subline {
            font-size: 12px;
            color: #6a615a;
            margin-top: 8px;
            line-height: 1.45;
        }

        .pill {
            display: inline-block;
            padding: 3px 10px 3px 9px;
            border-radius: 10px;
            font-size: 9.5px;
            font-weight: bold;
            letter-spacing: 0.2px;
        }

        .pill .dot {
            display: inline-block;
            width: 5px;
            height: 5px;
            border-radius: 2.5px;
            margin-right: 5px;
        }

        /* Spec sheet — invoice no. / dates / the amount in an ink cell. */
        .spec {
            margin-top: 20px;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #e2ddd6;
            border-radius: 12px;
            background-color: #fefdfb;
        }

        .spec td { padding: 12px 16px; border-left: 1px solid #e2ddd6; }
        .spec td.first { border-left: none; }
        .spec .value { font-size: 12px; margin-top: 5px; color: #1a1511; }

        .spec td.amount {
            background-color: #1a1511;
            color: #f5f1ec;
            border-left: none;
            border-radius: 0 11px 11px 0;
            width: 34%;
        }

        .spec td.amount.settled { background-color: #f0ece6; color: #1a1511; }
        .spec td.amount .eyebrow { color: #aaa39d; }
        .spec td.amount.settled .eyebrow { color: #6a615a; }
        .spec td.amount .figure { font-size: 28px; line-height: 1; margin-top: 6px; letter-spacing: -0.3px; }

        .parties { margin-top: 22px; }
        .parties td { width: 50%; padding-right: 24px; }
        .party-name { font-size: 14px; font-weight: bold; margin-top: 5px; margin-bottom: 2px; color: #1a1511; }
        .party-line { color: #6a615a; font-size: 10px; line-height: 1.35; }

        /* Line items — flat, hairline rows, mono index. */
        .items { margin-top: 22px; }
        .items th {
            text-align: left;
            padding: 0 8px 9px;
            border-bottom: 1px solid #1a1511;
        }
        .items th.right { text-align: right; }
        .items th.first, .items td.first { padding-left: 0; }
        .items th.last, .items td.last { padding-right: 0; }
        .items td {
            padding: 7px 8px;
            border-bottom: 1px solid #e2ddd6;
            font-size: 10.5px;
            line-height: 1.35;
        }
        .items .idx { color: #aaa39d; font-size: 9px; width: 28px; padding-top: 8.5px; }
        .items .desc { color: #1a1511; }
        .items .num { text-align: right; white-space: nowrap; font-size: 10px; }
        .items tr.included .desc { color: #6a615a; }
        .tag {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 7px;
            border-radius: 8px;
            background-color: #f0ece6;
            color: #6a615a;
            font-size: 8.5px;
            font-weight: bold;
        }

        .totals { width: 290px; margin-top: 14px; margin-left: auto; }
        .totals td { padding: 5px 0; font-size: 11px; color: #6a615a; }
        .totals td.num { text-align: right; color: #1a1511; }
        .totals .grand td { padding: 0; }
        .grand-block {
            background-color: #1a1511;
            color: #f5f1ec;
            border-radius: 10px;
            padding: 11px 16px;
        }
        .grand-block.settled { background-color: #f0ece6; color: #1a1511; }
        .grand-block .eyebrow { color: #aaa39d; padding-top: 9px; }
        .grand-block.settled .eyebrow { color: #6a615a; }
        .grand-block .figure { font-size: 25px; line-height: 1; text-align: right; color: #f5f1ec; }
        .grand-block.settled .figure { color: #1a1511; }

        .cards { margin-top: 22px; }
        .cards td { width: 50%; }
        .cards td.gap { width: 16px; }
        .card {
            border: 1px solid #e2ddd6;
            border-radius: 12px;
            background-color: #fefdfb;
            padding: 12px 16px;
        }
        .card .body { margin-top: 6px; font-size: 10px; color: #1a1511; line-height: 1.4; }

        .thanks { margin-top: 22px; font-size: 19px; color: #1a1511; }
        .thanks em { font-style: italic; color: #f1561e; }

        .footer {
            position: fixed;
            left: 52px;
            right: 52px;
            /* A4 is 1123px tall at dompdf's 96dpi; minus the 34px/78px page margins this sits
               ~24px into the bottom margin. (dompdf drops fixed boxes anchored with a negative
               `bottom`.) */
            top: 1034px;
            height: 30px;
            border-top: 1px solid #e2ddd6;
            padding-top: 10px;
        }
        .footer .wordmark { font-size: 13px; color: #1a1511; }
        .footer .wordmark .dot { color: #f1561e; font-style: normal; }

    </style>
</head>
<body>

    <div class="footer">
        <table>
            <tr>
                <td class="eyebrow" style="vertical-align: middle;">{{ $organization->name }} &nbsp;&middot;&nbsp; {{ $invoice->invoice_number }}</td>
                <td class="right" style="vertical-align: middle;">
                    <span class="eyebrow" style="letter-spacing: 1px;">Prepared with</span>
                    <span class="serif wordmark"><em>alimaplanner</em><span class="dot">.</span></span>
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
                                <div class="mono studio-caption">INVOICE</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="right" style="vertical-align: middle;">
                    <div class="eyebrow doc-label">Invoice no.</div>
                    <div class="mono doc-number">{{ $invoice->invoice_number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="sheet">
        <table>
            <tr>
                <td style="vertical-align: middle;">
                    <span class="eyebrow"><span class="square"></span>Issued {{ $date($invoice->issue_date) }} &mdash; for {{ $client->name }}</span>
                </td>
                <td class="right" style="vertical-align: middle;">
                    <span class="pill" style="background-color: {{ $tone['bg'] }}; color: {{ $tone['fg'] }};"><span class="dot" style="background-color: {{ $tone['dot'] }};"></span>{{ $invoice->displayStatusLabel() }}</span>
                </td>
            </tr>
        </table>

        <div class="serif headline">{{ $headlineLead }} <em>{{ $headlineAccent }}</em>.</div>
        <div class="subline">{{ $subline }}</div>

        <table class="spec">
            <tr>
                <td class="first">
                    <div class="eyebrow">Invoice no.</div>
                    <div class="mono value">{{ $invoice->invoice_number }}</div>
                </td>
                <td>
                    <div class="eyebrow">Issued</div>
                    <div class="mono value">{{ $date($invoice->issue_date) }}</div>
                </td>
                <td>
                    <div class="eyebrow">{{ $displayStatus === 'paid' ? 'Paid' : 'Due' }}</div>
                    <div class="mono value">
                        @if ($displayStatus === 'paid')
                            {{ $date($invoice->paid_at) }}
                        @else
                            {{ $invoice->due_date ? $date($invoice->due_date) : 'On receipt' }}
                        @endif
                    </div>
                </td>
                <td class="amount {{ $isSettled ? 'settled' : '' }}">
                    <div class="eyebrow">{{ $amountLabel }}</div>
                    <div class="serif figure">{{ $money($invoice->amount) }}</div>
                </td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td>
                    <div class="eyebrow">Billed to</div>
                    <div class="party-name">{{ $client->legal_name ?: $client->name }}</div>
                    @if ($client->legal_name && $client->legal_name !== $client->name)
                        <div class="party-line">{{ $client->name }}</div>
                    @endif
                    @if ($client->billing_address)
                        <div class="party-line">{!! nl2br(e($client->billing_address)) !!}</div>
                    @endif
                    @if ($client->invoiceRecipientEmail())
                        <div class="party-line">{{ $client->invoiceRecipientEmail() }}</div>
                    @endif
                </td>
                <td>
                    <div class="eyebrow">From</div>
                    <div class="party-name">{{ $organization->name }}</div>
                    @if ($organization->billing_details)
                        <div class="party-line">{!! nl2br(e($organization->billing_details)) !!}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th class="eyebrow first">No.</th>
                    <th class="eyebrow">Description</th>
                    <th class="eyebrow right" style="width: 36px;">Qty</th>
                    <th class="eyebrow right" style="width: 96px; white-space: nowrap;">Unit price</th>
                    <th class="eyebrow right last" style="width: 96px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @if ($hasItems)
                    @foreach ($items as $item)
                        <tr class="{{ $item->is_billable ? '' : 'included' }}">
                            <td class="mono idx first">{{ str_pad((string) ++$index, 2, '0', STR_PAD_LEFT) }}</td>
                            <td class="desc">
                                {{ $item->description }}
                                @unless ($item->is_billable)
                                    <span class="tag">Included</span>
                                @endunless
                            </td>
                            <td class="nums num">{{ $item->quantity }}</td>
                            <td class="nums num">{{ $item->is_billable && $item->price !== null ? $money($item->price) : '—' }}</td>
                            <td class="nums num last">{{ $item->is_billable ? $money($item->lineTotal()) : '—' }}</td>
                        </tr>
                    @endforeach
                @endif
                @if ($retainer > 0)
                    <tr>
                        <td class="mono idx first">{{ str_pad((string) ++$index, 2, '0', STR_PAD_LEFT) }}</td>
                        <td class="desc">{{ $retainerLabel }}</td>
                        <td class="nums num">1</td>
                        <td class="nums num">{{ $money($retainer) }}</td>
                        <td class="nums num last">{{ $money($retainer) }}</td>
                    </tr>
                @endif
                @if (! $hasItems && $retainer <= 0)
                    <tr>
                        <td class="mono idx first">01</td>
                        <td class="desc">{{ $invoice->description ?: 'Professional services' }}</td>
                        <td class="nums num">1</td>
                        <td class="nums num">{{ $money($invoice->amount) }}</td>
                        <td class="nums num last">{{ $money($invoice->amount) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <table class="totals">
            <tr class="grand">
                <td colspan="2">
                    <div class="grand-block {{ $isSettled ? 'settled' : '' }}">
                        <table>
                            <tr>
                                <td class="eyebrow">{{ match ($displayStatus) { 'paid' => 'Total paid', 'void' => 'Total (void)', default => 'Total due' } }}</td>
                                <td class="serif figure">{{ $money($invoice->amount) }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        @php
            $showPayment = ! $isSettled && $organization->payment_instructions;
            $showNotes = (bool) $invoice->description && ($hasItems || $retainer > 0);
            $showReceipt = $displayStatus === 'paid';
        @endphp

        @if ($showNotes || $showPayment || $showReceipt)
            <table class="cards">
                <tr>
                    @if ($showNotes)
                        <td>
                            <div class="card">
                                <div class="eyebrow">Notes</div>
                                <div class="body">{!! nl2br(e($invoice->description)) !!}</div>
                            </div>
                        </td>
                    @endif
                    @if ($showNotes && ($showPayment || $showReceipt))
                        <td class="gap"></td>
                    @endif
                    @if ($showPayment)
                        <td>
                            <div class="card">
                                <div class="eyebrow"><span class="square"></span>How to pay</div>
                                <div class="body">{!! nl2br(e($organization->payment_instructions)) !!}</div>
                                <div class="body muted">Please quote <span class="mono">{{ $invoice->invoice_number }}</span> as your reference.</div>
                            </div>
                        </td>
                    @elseif ($showReceipt)
                        <td>
                            <div class="card">
                                <div class="eyebrow">Payment received</div>
                                <div class="body">{{ $date($invoice->paid_at) }}@if ($invoice->payment_reference) &nbsp;&middot;&nbsp; Ref. <span class="mono">{{ $invoice->payment_reference }}</span>@endif</div>
                            </div>
                        </td>
                    @endif
                    @if (! ($showNotes && ($showPayment || $showReceipt)))
                        <td></td>
                    @endif
                </tr>
            </table>
        @endif

        @unless ($displayStatus === 'void')
            <div class="serif thanks">Thank you for working <em>with us</em>.</div>
        @endunless
    </div>
</body>
</html>
