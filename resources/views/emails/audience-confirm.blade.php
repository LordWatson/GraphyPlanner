{{--
    Step GTM-2.1 — the double opt-in. Same table layout as the other transactional emails (inline
    styles only, 560px, cream canvas), branded by the creator. No unsubscribe link: there is
    nothing to unsubscribe from until this link is followed, and ignoring it is the answer.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Confirm your email') }}</title>
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
                                {{ __('One tap and you are in.') }}
                            </h1>

                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                {{ __('Confirm that you want emails from :brand. If this was not you, ignore this message and nothing happens.', ['brand' => $brand]) }}
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                <tr>
                                    <td style="background-color:#FF5A1F; border-radius:999px;">
                                        <a href="{{ $confirmUrl }}" style="display:inline-block; padding:12px 24px; font-size:15px; font-weight:600; color:#FBFAF6; text-decoration:none;">
                                            {{ __('Yes, confirm my email') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0; font-size:13px; line-height:1.6; color:#6B6878;">
                                {{ __('This link works for :days days.', ['days' => $expiresInDays]) }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
