{{--
    Step GTM-2.2 / GTM-2.3 — every audience email after the confirmation: welcome, purchase
    follow-up, abandoned checkout and the owner's own broadcasts. Same table layout as the other
    transactional emails, branded by the creator. The unsubscribe link is not optional here — it
    is in the footer of every single one.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headline }}</title>
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
                                {{ $headline }}
                            </h1>

                            <p style="margin:0 0 8px; font-size:15px; line-height:1.6; color:#4A4857;">
                                {{ $greeting }}
                            </p>

                            <div style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857; white-space:pre-line;">{{ $body }}</div>

                            @if ($ctaUrl && $ctaLabel)
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
                                    <tr>
                                        <td style="background-color:#FF5A1F; border-radius:999px;">
                                            <a href="{{ $ctaUrl }}" style="display:inline-block; padding:12px 24px; font-size:15px; font-weight:600; color:#FBFAF6; text-decoration:none;">
                                                {{ $ctaLabel }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="border-top:1px solid #E1DDD0; padding:20px 32px; font-size:12px; line-height:1.6; color:#6B6878;">
                            {{ __('You are getting this because you gave :brand your email address.', ['brand' => $brand]) }}
                            <a href="{{ $unsubscribeUrl }}" style="color:#6B6878; text-decoration:underline;">{{ __('Unsubscribe') }}</a>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
