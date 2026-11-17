{{--
    Step S-3.1 — "Time to post.": an assisted Story is due. One screen, one button.

    Sent by `StoryPublishHandoffReady` to the team member who posts it (Alima's own chrome) or to
    the client's people (the agency's branding, through the shared header and footer partials).
    `$url` carries the handoff token, so this view is the only place it is ever written out.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:'Poppins', Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:12px; overflow:hidden; border:1px solid #E1DDD0;">
                    @include('emails.partials.brand-header')
                    <tr>
                        <td style="padding:32px;">
                            @if ($clientName)
                                <p style="margin:0 0 8px; font-family:'DM Mono', 'Courier New', monospace; font-size:11px; letter-spacing:0.1em; text-transform:uppercase; color:#6B6977;">
                                    {{ $clientName }}
                                </p>
                            @endif
                            <h1 style="margin:0 0 12px; font-family:'Poppins', Arial, sans-serif; font-size:24px; line-height:1.25; color:#181723; font-weight:600;">
                                {{ $title }}
                            </h1>
                            <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                {{ $lead }}
                                @if ($handle)
                                    Post it to <strong style="color:#181723;">{{ $handle }}</strong> on Instagram.
                                @endif
                            </p>

                            @if ($dueLabel)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; border:1px solid #E1DDD0; border-radius:8px; margin:0 0 20px;">
                                    <tr>
                                        <td style="padding:14px 18px;">
                                            <p style="margin:0 0 2px; font-family:'DM Mono', 'Courier New', monospace; font-size:11px; letter-spacing:0.1em; text-transform:uppercase; color:#6B6977;">
                                                {{ $late ? 'Was due' : 'Due' }}
                                            </p>
                                            <p style="margin:0; font-size:15px; line-height:1.5; color:#181723; font-weight:600;">
                                                {{ $dueLabel }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#181723;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:14px 32px; font-size:15px; font-weight:600; color:#F5F2E8; text-decoration:none; border-radius:999px;">
                                            Open the story
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            @if ($extras !== [])
                                <p style="margin:0 0 10px; font-size:14px; line-height:1.5; color:#181723; font-weight:600;">
                                    Then add these in Instagram
                                </p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; border-top:1px solid #E1DDD0;">
                                    @foreach ($extras as $extra)
                                        <tr>
                                            <td style="padding:10px 0; border-bottom:1px solid #E1DDD0; font-size:13px; line-height:1.5; color:#6B6977; width:130px; vertical-align:top;">
                                                {{ $extra['label'] }}
                                            </td>
                                            <td style="padding:10px 0; border-bottom:1px solid #E1DDD0; font-size:14px; line-height:1.5; color:#181723; word-break:break-word;">
                                                {{ $extra['value'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($placementNote)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ECE2E5; border:1px solid #DBC3DE; border-radius:8px; margin:0 0 20px;">
                                    <tr>
                                        <td style="padding:14px 18px; font-size:14px; line-height:1.6; color:#181723;">
                                            {{ $placementNote }}
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <p style="margin:0; font-size:12px; line-height:1.6; color:#6B6977;">
                                Instagram only allows links and stickers from its own app, so this one is posted by hand.
                                The button opens the story on your phone with everything ready to share. No password needed.
                            </p>
                        </td>
                    </tr>
                    @include('emails.partials.brand-footer')
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
