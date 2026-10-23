<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A reminder: content waiting for your review</title>
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
                                Just a gentle reminder
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Hi {{ $post->client->name }} team,
                            </p>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                A post is still waiting on your approval. When you have a moment, take a look and
                                let us know whether it's good to go, or if you'd like any changes.
                            </p>

                            @if ($post->master_caption)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; margin:0 0 24px;">
                                    <tr>
                                        <td style="padding:16px 18px; font-size:14px; line-height:1.6; color:#4A4857;">
                                            {{ \Illuminate\Support\Str::limit($post->master_caption, 240) }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#181723;">
                                        <a href="{{ $reviewUrl }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#F5F2E8; text-decoration:none; border-radius:999px;">
                                            Review this post
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#6B6977;">
                                Or copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px; font-size:13px; line-height:1.6; color:#205557; word-break:break-all;">
                                {{ $reviewUrl }}
                            </p>

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#6B6977;">
                                This link is unique to you and will expire in 7 days. No account or password is
                                needed to review and respond.
                            </p>
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
