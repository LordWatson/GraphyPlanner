<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Alima</title>
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
                                Welcome aboard, {{ \Illuminate\Support\Str::before($owner->name, ' ') ?: $owner->name }}.
                            </h1>
                            @if ($isBusiness)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    {{ $organizationName }} is set up on Alima. Let's get your brand ready: tell us your
                                    voice, connect your accounts and line up your first post — we'll guide you.
                                </p>
                            @else
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    {{ $organizationName }} is set up on Alima and you're its owner. Add your first client,
                                    invite the team and start planning — we're glad you're here.
                                </p>
                            @endif

                            @if ($setPasswordUrl)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    Our team created this workspace for you. Choose a password to sign in for the first time.
                                </p>
                            @elseif ($verifyUrl)
                                <p style="margin:0 0 20px; font-size:15px; line-height:1.6; color:#4A4857;">
                                    One quick step first: confirm this is your email address. The button below verifies
                                    your email and takes you straight into {{ $organizationName }} — no separate sign-in needed.
                                </p>
                            @endif

                            @php
                                $ctaUrl = $setPasswordUrl ?? $verifyUrl ?? $dashboardUrl;
                                $ctaLabel = $setPasswordUrl
                                    ? 'Choose your password'
                                    : ($verifyUrl
                                        ? 'Verify my email & get started'
                                        : ($isBusiness ? 'Set up my brand' : "Let's get started"));
                            @endphp

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                                <tr>
                                    <td style="border-radius:999px; background-color:#181723;">
                                        <a href="{{ $ctaUrl }}" style="display:inline-block; padding:12px 28px; font-size:15px; font-weight:600; color:#F5F2E8; text-decoration:none; border-radius:999px;">
                                            {{ $ctaLabel }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            @if ($setPasswordUrl)
                                <p style="margin:0 0 24px; font-size:12px; line-height:1.6; color:#6B6977;">
                                    This link expires after {{ config('auth.passwords.users.expire', 60) }} minutes. If it has, request a fresh one at
                                    <a href="{{ $forgotPasswordUrl }}" style="color:#205557;">{{ $forgotPasswordUrl }}</a> using this email address.
                                </p>
                            @elseif ($verifyUrl)
                                <p style="margin:0 0 24px; font-size:12px; line-height:1.6; color:#6B6977;">
                                    This link is valid for 48 hours. If it has expired, sign in with your email and password
                                    and we'll send you a fresh one.
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0; background-color:#EFE6F3; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px; font-size:13px; line-height:1.6; color:#181723;">
                                        @if ($isBusiness)
                                            <strong style="font-weight:600;">Three quick wins:</strong> set your brand voice,
                                            connect a social account, then schedule your first post. Got a question? Let's talk.
                                        @else
                                            <strong style="font-weight:600;">Three quick wins:</strong> add a client and fill in their Brand Brain,
                                            connect a social account, then draft your first post. Got a question? Let's talk.
                                        @endif
                                    </td>
                                </tr>
                            </table>
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
