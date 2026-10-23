<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your week on social</title>
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
                                Your week on social
                            </h1>
                            <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Here's how {{ $brand->name }} did over the last {{ $headline['period_days'] }} days.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td width="50%" style="padding:0 8px 16px 0;">
                                        <div style="padding:16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px;">
                                            <p style="margin:0; font-size:12px; color:#6B6977; text-transform:uppercase; letter-spacing:0.05em;">Posts live</p>
                                            <p style="margin:4px 0 0; font-size:26px; font-weight:700; color:#181723;">{{ number_format($headline['published']) }}</p>
                                        </div>
                                    </td>
                                    <td width="50%" style="padding:0 0 16px 8px;">
                                        <div style="padding:16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px;">
                                            <p style="margin:0; font-size:12px; color:#6B6977; text-transform:uppercase; letter-spacing:0.05em;">Streak</p>
                                            <p style="margin:4px 0 0; font-size:26px; font-weight:700; color:#181723;">{{ $headline['streak'] }} {{ \Illuminate\Support\Str::plural('week', $headline['streak']) }}</p>
                                        </div>
                                    </td>
                                </tr>
                                @if ($headline['reach'] !== null || $headline['followers'] !== null)
                                    <tr>
                                        <td width="50%" style="padding:0 8px 0 0;">
                                            <div style="padding:16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px;">
                                                <p style="margin:0; font-size:12px; color:#6B6977; text-transform:uppercase; letter-spacing:0.05em;">Reach</p>
                                                <p style="margin:4px 0 0; font-size:26px; font-weight:700; color:#181723;">{{ $headline['reach'] === null ? '—' : number_format($headline['reach']) }}</p>
                                            </div>
                                        </td>
                                        <td width="50%" style="padding:0 0 0 8px;">
                                            <div style="padding:16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px;">
                                                <p style="margin:0; font-size:12px; color:#6B6977; text-transform:uppercase; letter-spacing:0.05em;">Followers</p>
                                                <p style="margin:4px 0 0; font-size:26px; font-weight:700; color:#181723;">{{ $headline['followers'] === null ? '—' : number_format($headline['followers']) }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </table>

                            @if (! empty($takeaways))
                                <h2 style="margin:0 0 12px; font-size:14px; color:#181723;">What the numbers say</h2>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                    @foreach ($takeaways as $takeaway)
                                        <tr>
                                            <td style="padding:12px 16px; background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; display:block; font-size:14px; line-height:1.5; color:#4A4857;">
                                                {{ $takeaway }}
                                            </td>
                                        </tr>
                                        <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
                                    @endforeach
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 8px;">
                                <tr>
                                    <td style="background-color:#205557; border-radius:8px;">
                                        <a href="{{ $insightsUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#F5F2E8; text-decoration:none;">See your insights</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. You're getting this weekly summary because it's on for your
                                workspace — turn it off any time in Workspace settings.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
