<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New daily brief</title>
</head>
<body style="margin:0; padding:0; background-color:#fbf9f5; font-family:'Instrument Sans', 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fbf9f5; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e7e2d9;">
                    <tr>
                        <td style="background-color:#241f1a; padding:24px 32px;">
                            <span style="font-family:'Playfair Display', Georgia, serif; font-style:italic; color:#ffffff; font-size:22px; font-weight:600; letter-spacing:-0.02em;">alimaplanner.</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 12px; font-family:'Playfair Display', Georgia, serif; font-size:22px; line-height:1.3; color:#1f1f1f; font-weight:600;">
                                New daily brief for {{ $client->name }}
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b4b4b;">
                                Here are today's top trends and fresh post ideas, discovered by AlimaPlanner's AI strategist:
                            </p>

                            <h2 style="margin:0 0 8px; font-size:14px; color:#1f1f1f;">Trends</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                @forelse (($brief->trends ?? []) as $trend)
                                    <tr>
                                        <td style="padding:12px 16px; background-color:#fbf9f5; border:1px solid #e7e2d9; border-radius:8px; display:block;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#1f1f1f;">{{ $trend['title'] ?? '' }}</p>
                                            <p style="margin:0; font-size:13px; color:#4b4b4b;">{{ $trend['summary'] ?? '' }}</p>
                                        </td>
                                    </tr>
                                    <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                @empty
                                    <tr><td style="font-size:13px; color:#9a9a9a;">No trends found today.</td></tr>
                                @endforelse
                            </table>

                            <h2 style="margin:0 0 8px; font-size:14px; color:#1f1f1f;">Post ideas</h2>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0;">
                                @forelse (($brief->ideas ?? []) as $idea)
                                    <tr>
                                        <td style="padding:12px 16px; background-color:#fbf9f5; border:1px solid #e7e2d9; border-radius:8px; display:block;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#1f1f1f;">{{ $idea['title'] ?? '' }}</p>
                                            <p style="margin:0; font-size:13px; color:#4b4b4b;">{{ $idea['description'] ?? '' }}</p>
                                        </td>
                                    </tr>
                                    <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                @empty
                                    <tr><td style="font-size:13px; color:#9a9a9a;">No fresh ideas today.</td></tr>
                                @endforelse
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#fbf9f5; border-top:1px solid #e7e2d9;">
                            <p style="margin:0; font-size:12px; color:#9a9a9a;">
                                &copy; {{ now()->year }} AlimaPlanner. This is an automated message, please don't reply
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
