<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page {
            margin: 0 0 40px 0;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #2b2420;
            font-size: 12px;
            line-height: 1.5;
            margin: 0;
        }

        .page-padding {
            padding: 0 42px;
        }

        .band {
            background-color: #1a1512;
            background-image: linear-gradient(120deg, #1a1512 0%, #2e2520 45%, #a4471c 150%);
            padding: 34px 42px 30px 42px;
            margin-bottom: 0;
        }

        .band table {
            width: 100%;
        }

        .band td {
            vertical-align: top;
        }

        .logo-mark {
            display: inline-block;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background-color: #e8824a;
            color: #1a1512;
            font-size: 16px;
            font-weight: 700;
            text-align: center;
            line-height: 34px;
        }

        .brand-row td {
            vertical-align: middle;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 0.5px;
            padding-left: 10px;
        }

        .brand-accent {
            color: #e8824a;
        }

        .brand-sub {
            font-size: 9px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #c9beb3;
            margin-top: 6px;
            padding-left: 44px;
        }

        .invoice-title {
            font-size: 26px;
            font-weight: 700;
            color: #ffffff;
            text-align: right;
            letter-spacing: 3px;
        }

        .invoice-meta {
            text-align: right;
            color: #e3d9cf;
            font-size: 10.5px;
            margin-top: 8px;
        }

        .status-pill {
            display: inline-block;
            margin-top: 12px;
            padding: 4px 12px;
            border-radius: 10px;
            background-color: rgba(232, 130, 74, 0.25);
            color: #f7c3a1;
            font-size: 9px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            font-weight: 700;
        }

        .accent-strip {
            height: 5px;
            background-image: linear-gradient(90deg, #d9641e 0%, #e8824a 45%, #f3b27a 100%);
            margin-bottom: 30px;
        }

        .section-label {
            font-size: 9px;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: #b4581f;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .parties {
            width: 100%;
            margin-bottom: 28px;
            border-collapse: separate;
            border-spacing: 14px 0;
            margin-left: -14px;
            margin-right: -14px;
        }

        .parties td {
            vertical-align: top;
            width: 50%;
            padding: 16px 18px;
            background-color: #faf6f1;
            border: 1px solid #ece4da;
            border-top: 2px solid #d9641e;
            border-radius: 3px;
        }

        .party-name {
            font-size: 15px;
            font-weight: 700;
            color: #1a1512;
        }

        .party-sub {
            font-size: 10px;
            color: #8a8178;
            margin-top: 3px;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        table.items thead th {
            text-align: left;
            font-size: 9px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #ffffff;
            background-color: #1a1512;
            padding: 11px 12px;
        }

        table.items thead th:first-child {
            border-top-left-radius: 5px;
            border-bottom-left-radius: 5px;
        }

        table.items thead th:last-child {
            border-top-right-radius: 5px;
            border-bottom-right-radius: 5px;
        }

        table.items tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #ece4da;
            font-size: 11px;
        }

        table.items tbody tr:nth-child(even) td {
            background-color: #faf6f1;
        }

        table.items tbody td.not-billable {
            color: #a39d95;
            font-style: italic;
        }

        table.items tbody td:first-child {
            border-left: 2px solid transparent;
        }

        .text-right {
            text-align: right;
        }

        table.totals {
            width: 300px;
            margin-left: auto;
            border-collapse: collapse;
            margin-top: 4px;
        }

        table.totals td {
            padding: 7px 12px;
            font-size: 11px;
            color: #5c554c;
        }

        table.totals tr.grand-total td {
            background-color: #1a1512;
            background-image: linear-gradient(90deg, #1a1512 0%, #2e2520 100%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            padding: 12px;
        }

        table.totals tr.grand-total td:first-child {
            border-radius: 5px 0 0 5px;
        }

        table.totals tr.grand-total td:last-child {
            border-radius: 0 5px 5px 0;
            color: #f3b27a;
        }

        .notes {
            margin-top: 28px;
            padding: 16px 18px;
            background-color: #faf6f1;
            border-left: 3px solid #d9641e;
            border-radius: 3px;
            font-size: 11px;
        }

        .thanks {
            margin-top: 30px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #1a1512;
            letter-spacing: 0.5px;
        }

        .thanks .brand-accent {
            color: #d9641e;
        }

        .footer {
            margin-top: 34px;
            padding: 18px 42px 0 42px;
            border-top: 1px solid #ece4da;
            font-size: 9px;
            color: #a39d95;
            text-align: center;
            letter-spacing: 0.6px;
        }

        .footer .dot {
            color: #d9641e;
        }
    </style>
</head>
<body>
    <div class="band">
        <table class="brand-row">
            <tr>
                <td>
                    <span class="logo-mark">{{ strtoupper(substr($organization->name, 0, 1)) }}</span>
                    <span class="brand">{{ $organization->name }}<span class="brand-accent">.</span></span>
                </td>
                <td>
                    <div class="invoice-title">INVOICE</div>
                </td>
            </tr>
        </table>
        <table>
            <tr>
                <td>
                    <div class="brand-sub">Design &amp; Content Studio</div>
                </td>
                <td>
                    <div class="invoice-meta">
                        #{{ $invoice->invoice_number }}<br>
                        Issued {{ $invoice->issue_date->format('M j, Y') }}
                        @if ($invoice->due_date)
                            &middot; Due {{ $invoice->due_date->format('M j, Y') }}
                        @endif
                    </div>
                    <div class="text-right">
                        <span class="status-pill">{{ $invoice->status->label() }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>
    <div class="accent-strip"></div>

    <div class="page-padding">
        <table class="parties">
            <tr>
                <td>
                    <div class="section-label">Billed to</div>
                    <div class="party-name">{{ $client->name }}</div>
                    <div class="party-sub">Client account</div>
                </td>
                <td>
                    <div class="section-label">Currency</div>
                    <div class="party-name">{{ strtoupper($invoice->currency) }}</div>
                    <div class="party-sub">All amounts below are in this currency</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Price</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td class="{{ $item->is_billable ? '' : 'not-billable' }}">
                            {{ $item->description }}
                            @unless ($item->is_billable)
                                (not billable)
                            @endunless
                        </td>
                        <td class="text-right">{{ $item->quantity }}</td>
                        <td class="text-right">
                            {{ $item->is_billable && $item->price !== null ? \App\Support\MoneyFormatter::format((float) $item->price, $invoice->currency) : '—' }}
                        </td>
                        <td class="text-right">{{ \App\Support\MoneyFormatter::format($item->lineTotal(), $invoice->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Items subtotal</td>
                <td class="text-right">{{ \App\Support\MoneyFormatter::format($itemsSubtotal, $invoice->currency) }}</td>
            </tr>
            <tr>
                <td>Retainer</td>
                <td class="text-right">{{ \App\Support\MoneyFormatter::format((float) $invoice->retainer_amount_snapshot, $invoice->currency) }}</td>
            </tr>
            <tr class="grand-total">
                <td>Total due</td>
                <td class="text-right">{{ \App\Support\MoneyFormatter::format((float) $invoice->amount, $invoice->currency) }}</td>
            </tr>
        </table>

        @if ($invoice->description)
            <div class="notes">
                <div class="section-label">Notes</div>
                <div>{{ $invoice->description }}</div>
            </div>
        @endif

        <div class="thanks">Thank you for working with us<span class="brand-accent">.</span></div>
    </div>

    <div class="footer">
        {{ $organization->name }} <span class="dot">&middot;</span> Generated by Graphy
    </div>
</body>
</html>
