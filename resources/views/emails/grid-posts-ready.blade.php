<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ready for your review</title>
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
                            <p style="margin:0 0 8px; font-family:'DM Mono', 'Courier New', monospace; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#6B6977;">
                                {{ $plan->name }}
                            </p>
                            <h1 style="margin:0 0 12px; font-family:'Poppins', Arial, sans-serif; font-size:22px; line-height:1.3; color:#181723; font-weight:600;">
                                {{ $count === 1 ? 'A caption from your grid is' : 'The captions for your grid are' }} ready for your review.
                            </h1>
                            <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#4A4857;">
                                You've already approved how the grid looks. These are the words that go with each post —
                                approve them, or tell us on the post what to change.
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#181723;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#F5F2E8; text-decoration:none; border-radius:999px;">
                                            Review the captions
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. Marketing brighter together.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
