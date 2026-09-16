<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Posts scheduled to go out soon</title>
</head>
<body style="margin:0; padding:0; background-color:#fbf9f5; font-family:'Instrument Sans', 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fbf9f5; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e7e2d9;">
                    <tr>
                        <td style="background-color:#241f1a; padding:24px 32px;">
                            <span style="font-family:'Playfair Display', Georgia, serif; font-style:italic; color:#ffffff; font-size:22px; font-weight:600; letter-spacing:-0.02em;">graphy.</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 12px; font-family:'Playfair Display', Georgia, serif; font-size:22px; line-height:1.3; color:#1f1f1f; font-weight:600;">
                                {{ count($occurrences) === 1 ? 'A post is scheduled to go out soon' : count($occurrences).' posts are scheduled to go out soon' }}
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b4b4b;">
                                Here's what's due to be published for {{ $organization->name }} in the next {{ $days }} {{ Str::plural('day', $days) }}:
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                @foreach ($occurrences as $occurrence)
                                    <tr>
                                        <td style="padding:14px 16px; background-color:#fbf9f5; border:1px solid #e7e2d9; border-radius:8px; margin-bottom:10px; display:block;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#1f1f1f;">
                                                {{ $occurrence['client_name'] ?? 'Unknown client' }}
                                                @if ($occurrence['handle'])
                                                    &mdash; {{ $occurrence['handle'] }}
                                                @endif
                                            </p>
                                            <p style="margin:0 0 4px; font-size:13px; color:#7a7a7a;">
                                                {{ $occurrence['scheduled_at_utc']?->toDayDateTimeString() }} UTC
                                                @if ($occurrence['platform'])
                                                    &middot; {{ ucfirst($occurrence['platform']) }}
                                                @endif
                                            </p>
                                            @if ($occurrence['master_caption'])
                                                <p style="margin:0; font-size:13px; color:#4b4b4b;">
                                                    {{ \Illuminate\Support\Str::limit($occurrence['master_caption'], 140) }}
                                                </p>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                @endforeach
                            </table>

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9a9a9a;">
                                This is a one-time reminder per post — you won't be notified again for the same post.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#fbf9f5; border-top:1px solid #e7e2d9;">
                            <p style="margin:0; font-size:12px; color:#9a9a9a;">
                                &copy; {{ now()->year }} Graphy. This is an automated message, please don't reply
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
