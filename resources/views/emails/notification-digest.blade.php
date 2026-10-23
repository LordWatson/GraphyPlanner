<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your daily digest</title>
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
                            <h1 style="margin:0 0 6px; font-size:22px; line-height:1.3; color:#181723; font-weight:600;">
                                Your daily digest
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                Hi {{ $name }}, here's what happened in the last day.
                            </p>

                            @foreach ($items as $item)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; margin:0 0 12px;">
                                    <tr>
                                        <td style="padding:14px 16px;">
                                            <p style="margin:0 0 4px; font-size:14px; font-weight:600; color:#181723;">
                                                @if ($item['url'])
                                                    <a href="{{ $item['url'] }}" style="color:#205557; text-decoration:none;">{{ $item['title'] }}</a>
                                                @else
                                                    {{ $item['title'] }}
                                                @endif
                                            </p>
                                            @if ($item['body'])
                                                <p style="margin:0; font-size:13px; line-height:1.5; color:#4A4857;">{{ $item['body'] }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            <p style="margin:20px 0 0; font-size:12px; line-height:1.6; color:#6B6977;">
                                Tune what lands in your digest, or turn it off, in your notification preferences.
                            </p>
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
