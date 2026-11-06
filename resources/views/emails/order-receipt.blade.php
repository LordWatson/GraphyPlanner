{{--
    Step CG-4.3 / CG-4.5 — the buyer's receipt and delivery. Same table layout as the other
    transactional emails (inline styles only, 560px, cream canvas), branded by the creator rather
    than by Alima: they bought from the creator, we only carried it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $product->title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:'Poppins', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:12px; overflow:hidden; border:1px solid #E1DDD0;">
                    <tr>
                        <td style="background-color:#205557; padding:24px 32px; font-size:18px; font-weight:600; color:#F5F2E8;">
                            {{ $brand }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 12px; font-size:22px; line-height:1.3; color:#181723; font-weight:600;">
                                @switch($product->type->value)
                                    @case('download') Your download is ready. @break
                                    @case('booking') You are booked in. @break
                                    @case('tip') Thank you — that means a lot. @break
                                    @default {{ $order->answer !== null ? 'Your answer.' : 'Your question is in.' }}
                                @endswitch
                            </h1>

                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                {{ $product->title }} — {{ $amount }}.
                            </p>

                            @if ($bookingLabel)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    <strong>{{ $bookingLabel }}</strong> ({{ $timezone }}). The attached calendar file
                                    adds it to your diary.
                                </p>
                            @endif

                            @if ($order->answer !== null)
                                <p style="margin:0 0 20px; padding:16px; background-color:#F5F2E8; border-radius:8px; font-size:15px; line-height:1.6; color:#181723;">
                                    {{ $order->answer }}
                                </p>
                            @elseif ($product->type->value === 'qa')
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    Your card has been authorised but not charged. You are only charged when
                                    {{ $brand }} answers — if they do not, the hold is released.
                                </p>
                            @endif

                            @if ($downloadUrl)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
                                    <tr>
                                        <td style="border-radius:999px; background-color:#181723;">
                                            <a href="{{ $downloadUrl }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#F5F2E8; text-decoration:none; border-radius:999px;">
                                                Download
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:0 0 20px; font-size:13px; line-height:1.6; color:#6B6878;">
                                    The link works for {{ $product->downloadExpiryHours() }} hours and up to
                                    {{ $product->downloadLimit() }} downloads. Need it again? Open your receipt page
                                    and ask for a fresh link.
                                </p>
                            @endif

                            @if ($manageBookingUrl)
                                <p style="margin:0 0 20px; font-size:13px; line-height:1.6; color:#6B6878;">
                                    Need to move or cancel it?
                                    <a href="{{ $manageBookingUrl }}" style="color:#205557;">Manage your booking</a>.
                                </p>
                            @endif

                            <p style="margin:0; font-size:13px; line-height:1.6; color:#6B6878;">
                                Order #{{ $order->id }}. Questions about this purchase go to {{ $brand }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
