<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're invited to join the team</title>
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
                                You're invited to join {{ $invitation->organization->name }} on Graphy
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b4b4b;">
                                Hi,
                            </p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b4b4b;">
                                You've been invited to join {{ $invitation->organization->name }} on Graphy as a
                                {{ $invitation->role->label() }}. Set up your account to get started.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#e2672e;">
                                        <a href="{{ $acceptUrl }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                                            Accept invitation
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#9a9a9a;">
                                Or copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px; font-size:13px; line-height:1.6; color:#e2672e; word-break:break-all;">
                                {{ $acceptUrl }}
                            </p>

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9a9a9a;">
                                This invitation is unique to you and will expire in 7 days.
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
