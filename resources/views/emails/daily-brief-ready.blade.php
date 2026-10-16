<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New daily brief</title>
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
                            <h1 style="margin:0 0 12px; font-family:'Poppins', Arial, sans-serif; font-size:22px; line-height:1.3; color:#181723; font-weight:600;">
                                New daily brief for {{ $client->name }}
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Here are today's top trends and fresh post ideas, discovered by Alima's AI strategist:
                            </p>

                            <h2 style="margin:0 0 8px; font-size:14px; color:#181723;">Trends</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                @forelse (($brief->trends ?? []) as $trend)
                                    <tr>
                                        <td style="padding:12px 16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; display:block;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#181723;">{{ $trend['title'] ?? '' }}</p>
                                            <p style="margin:0; font-size:13px; color:#4A4857;">{{ $trend['summary'] ?? '' }}</p>
                                        </td>
                                    </tr>
                                    <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                @empty
                                    <tr><td style="font-size:13px; color:#6B6977;">No trends found today.</td></tr>
                                @endforelse
                            </table>

                            <h2 style="margin:0 0 8px; font-size:14px; color:#181723;">Post ideas</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0;">
                                @forelse (($brief->ideas ?? []) as $idea)
                                    <tr>
                                        <td style="padding:12px 16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; display:block;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#181723;">{{ $idea['title'] ?? '' }}</p>
                                            <p style="margin:0; font-size:13px; color:#4A4857;">{{ $idea['description'] ?? '' }}</p>
                                        </td>
                                    </tr>
                                    <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                @empty
                                    <tr><td style="font-size:13px; color:#6B6977;">No fresh ideas today.</td></tr>
                                @endforelse
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. Marketing brighter together. This is an automated message, please don't reply
                                directly to this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
