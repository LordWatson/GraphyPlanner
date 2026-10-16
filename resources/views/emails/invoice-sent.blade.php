<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isReminder ? 'Payment reminder' : 'New invoice' }} — {{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:'Poppins', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:14px; overflow:hidden; border:1px solid #E1DDD0;">
                    <tr>
                        <td style="background-color:#181723; padding:22px 32px;">
                            <span style="font-family:'Poppins', Arial, sans-serif; color:#F5F2E8; font-size:22px;">{{ $organization->name }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 10px; font-family:'DM Mono', Menlo, monospace; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:#6B6977;">
                                {{ $isReminder ? 'Payment reminder' : 'Invoice' }} — {{ $invoice->invoice_number }}
                            </p>
                            <h1 style="margin:0 0 18px; font-family:'Poppins', Arial, sans-serif; font-weight:600; font-size:34px; line-height:1.05; color:#181723;">
                                @if ($isReminder && $invoice->isOverdue())
                                    Payment <em style="color:#205557; font-style:normal;">overdue</em>.
                                @elseif ($isReminder)
                                    A friendly <em style="color:#205557; font-style:normal;">reminder</em>.
                                @else
                                    Your <em style="color:#205557; font-style:normal;">invoice</em>.
                                @endif
                            </h1>

                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Hi {{ $client->name }},
                            </p>

                            @if ($personalMessage)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    {!! nl2br(e($personalMessage)) !!}
                                </p>
                            @elseif ($isReminder)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    Just a quick note that invoice {{ $invoice->invoice_number }} is still awaiting payment{{ $invoice->isOverdue() ? ' and is now past its due date' : '' }}. If you've already paid, thank you — please ignore this email.
                                </p>
                            @else
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    Please find invoice {{ $invoice->invoice_number }} from {{ $organization->name }} attached. Thank you for working with us.
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; background-color:#181723; border-radius:12px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 6px; font-family:'DM Mono', Menlo, monospace; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:#AEACB5;">Amount due</p>
                                        <p style="margin:0; font-family:'Poppins', Arial, sans-serif; font-size:32px; line-height:1; color:#F5F2E8;">{{ $amount }}</p>
                                    </td>
                                    <td align="right" style="padding:18px 20px; vertical-align:bottom;">
                                        <p style="margin:0 0 6px; font-family:'DM Mono', Menlo, monospace; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:#AEACB5;">Due</p>
                                        <p style="margin:0; font-family:'DM Mono', Menlo, monospace; font-size:14px; color:#F5F2E8;">{{ $invoice->due_date?->format('j M Y') ?? 'On receipt' }}</p>
                                    </td>
                                </tr>
                            </table>

                            @if ($organization->payment_instructions)
                                <p style="margin:0 0 6px; font-family:'DM Mono', Menlo, monospace; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:#6B6977;">How to pay</p>
                                <p style="margin:0 0 24px; font-size:14px; line-height:1.6; color:#4A4857;">
                                    {!! nl2br(e($organization->payment_instructions)) !!}<br>
                                    Please quote <strong>{{ $invoice->invoice_number }}</strong> as your reference.
                                </p>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#205557;">
                                        <a href="{{ $portalUrl }}" style="display:inline-block; padding:12px 26px; font-size:15px; font-weight:600; color:#FBFAF6; text-decoration:none; border-radius:999px;">
                                            View invoice
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#6B6977;">
                                Portal access required — the PDF is also attached to this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; color:#6B6977;">
                                Sent by {{ $organization->name }} via Alima. Questions about this invoice? Get in touch with your studio contact.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
