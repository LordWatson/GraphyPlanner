<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New content ready for your review</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f7; font-family: 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(76,29,149,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6d28d9,#4f46e5,#0ea5e9); padding:28px 32px;">
                            <span style="color:#ffffff; font-size:20px; font-weight:700; letter-spacing:-0.02em;">Graphy</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 12px; font-size:22px; line-height:1.3; color:#111827; font-weight:700;">
                                New content is ready for your review
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b5563;">
                                Hi {{ $post->client->name }} team,
                            </p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4b5563;">
                                A new post is waiting on your approval. Take a look and let us know whether it's
                                good to go, or if you'd like any changes.
                            </p>

                            @if ($post->review_message)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; margin:0 0 24px;">
                                    <tr>
                                        <td style="padding:16px 18px; font-size:14px; line-height:1.6; color:#3730a3;">
                                            {{ $post->review_message }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if ($post->master_caption)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; margin:0 0 24px;">
                                    <tr>
                                        <td style="padding:16px 18px; font-size:14px; line-height:1.6; color:#374151;">
                                            {{ \Illuminate\Support\Str::limit($post->master_caption, 240) }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:8px; background-color:#4f46e5;">
                                        <a href="{{ $reviewUrl }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                                            Review this post
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#9ca3af;">
                                Or copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px; font-size:13px; line-height:1.6; color:#4f46e5; word-break:break-all;">
                                {{ $reviewUrl }}
                            </p>

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#9ca3af;">
                                This link is unique to you and will expire in 7 days. No account or password is
                                needed to review and respond.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#f9fafb; border-top:1px solid #e5e7eb;">
                            <p style="margin:0; font-size:12px; color:#9ca3af;">
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
