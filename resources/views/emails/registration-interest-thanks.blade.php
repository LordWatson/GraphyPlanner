@php
    $mono = "'DM Mono', 'Courier New', monospace";
    $sans = "'Poppins', Arial, sans-serif";
    $replyTo = config('mail.registration_interest.from');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>You're on the list</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@500&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:{!! $sans !!};">
    {{-- Inbox preview line. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
        A real human will be in touch within 24 hours (usually within a few). Here's what happens next.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:12px; overflow:hidden; border:1px solid #E1DDD0;">
                    {{-- Header band. --}}
                    <tr>
                        <td style="background-color:#205557; padding:22px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <img src="{{ asset('brand/alima_logo_cream@2x.png') }}" width="120" height="36" alt="Alima" style="display:block; border:0; width:120px; height:36px;">
                                    </td>
                                    <td align="right" style="font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#D2E842; text-transform:uppercase;">
                                        Early access
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Spectrum rule. --}}
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td height="6" style="background-color:#181723; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#F03D2A; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#4E91D4; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#C9A4D8; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#D2E842; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#205557; font-size:0; line-height:0;">&nbsp;</td>
                                    <td height="6" style="background-color:#F5F2E8; font-size:0; line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Ink hero. --}}
                    <tr>
                        <td style="background-color:#181723; padding:40px 32px 36px;">
                            <p style="margin:0 0 18px; font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#D2E842; text-transform:uppercase;">
                                &#9632;&nbsp; You're on the list
                            </p>
                            <h1 style="margin:0; font-family:{!! $sans !!}; font-size:38px; line-height:1.1; letter-spacing:-0.02em; color:#F5F2E8; font-weight:600;">
                                Thanks, {{ $firstName }}.<br>
                                You're <span style="color:#D2E842;">in.</span>
                            </h1>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 20px;">
                                <tr><td width="32" height="4" style="background-color:#F03D2A; font-size:0; line-height:0;">&nbsp;</td></tr>
                            </table>
                            <p style="margin:0; font-size:16px; line-height:1.6; color:#D9D6CC;">
                                We're onboarding our first studios by hand, so a real person from the team will be in touch
                                <strong style="color:#F5F2E8; font-weight:600;">within 24 hours</strong> — usually within a few.
                            </p>
                        </td>
                    </tr>

                    {{-- What happens next. --}}
                    <tr>
                        <td style="padding:36px 32px 8px;">
                            <p style="margin:0 0 6px; font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#5F5D6B; text-transform:uppercase;">
                                What happens next
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1.5px solid #181723;">
                                @foreach ([
                                    ['01', 'We say hello', 'A quick email or call to learn about '.$interest->company_name.' — your clients, your channels, what\'s slowing you down.'],
                                    ['02', 'A 20-minute walkthrough', 'Brand Brain, the content calendar and client approvals, shown on work that looks like yours.'],
                                    ['03', 'We set you up', 'Your workspace, your brands and your first posts, done together. No setup homework.'],
                                ] as [$step, $title, $body])
                                    <tr>
                                        <td width="44" valign="top" style="padding:16px 0; border-bottom:1px solid #E1DDD0; font-family:{!! $mono !!}; font-size:13px; color:#205557;">
                                            {{ $step }}
                                        </td>
                                        <td valign="top" style="padding:16px 0; border-bottom:1px solid #E1DDD0;">
                                            <p style="margin:0 0 4px; font-size:15px; line-height:1.4; color:#181723; font-weight:600;">{{ $title }}</p>
                                            <p style="margin:0; font-size:14px; line-height:1.6; color:#4A4857;">{{ $body }}</p>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    {{-- Studio note + CTA. --}}
                    <tr>
                        <td style="padding:24px 32px 36px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#EFE6F3; border-radius:12px;">
                                <tr>
                                    <td style="padding:20px 22px;">
                                        <p style="margin:0 0 6px; font-size:15px; color:#181723; font-weight:600;">Can't wait? Let's talk.</p>
                                        <p style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#181723;">
                                            Hit reply and tell us anything that'd help — it lands with the team, not a robot.
                                        </p>
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background-color:#181723; border-radius:999px;">
                                                    <a href="mailto:{{ $replyTo }}?subject={{ rawurlencode('About '.$interest->company_name) }}" style="display:inline-block; padding:12px 24px; font-family:{!! $sans !!}; font-size:14px; font-weight:600; color:#F5F2E8; text-decoration:none;">Say hello &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer. --}}
                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0 0 4px; font-size:12px; line-height:1.6; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. Marketing brighter together.
                            </p>
                            <p style="margin:0; font-family:{!! $mono !!}; font-size:11px; line-height:1.6; color:#6B6977;">
                                You're getting this because you registered interest at {{ parse_url(config('app.url'), PHP_URL_HOST) }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
