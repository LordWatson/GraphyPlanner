@php
    $mono = "'DM Mono', 'Courier New', monospace";
    $sans = "'Poppins', Arial, sans-serif";
    $firstName = \Illuminate\Support\Str::before($interest->name, ' ') ?: $interest->name;
    $ipPlace = collect([$interest->city, $interest->country])->filter()->implode(', ');
    $sections = [
        'Contact' => [
            'Name' => e($interest->name),
            'Email' => '<a href="mailto:'.e($interest->email).'" style="color:#205557; text-decoration:underline;">'.e($interest->email).'</a>',
            'Phone' => $interest->phone ? '<a href="tel:'.e(preg_replace('/[^0-9+]/', '', $interest->phone)).'" style="color:#205557; text-decoration:underline;">'.e($interest->phone).'</a>' : null,
            'Company' => e($interest->company_name),
            'Location' => $interest->location ? e($interest->location) : null,
        ],
        'Visit' => [
            'Came from' => e($source),
            'Campaign' => $interest->utm_campaign ? e($interest->utm_campaign) : null,
            'Referral' => $interest->referral_code ? e($interest->referral_code) : null,
            'IP location' => $ipPlace !== '' ? e($ipPlace) : null,
            'Timezone' => $interest->timezone ? e($interest->timezone) : null,
            'IP address' => $interest->ip_address ? e($interest->ip_address) : null,
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>New registration</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@500&family=Poppins:wght@400;600&display=swap" rel="stylesheet">
</head>
<body style="margin:0; padding:0; background-color:#F5F2E8; font-family:{!! $sans !!};">
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
        {{ $interest->name }} · {{ $source }} · reply by {{ $replyBy->format('D j M, H:i') }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F2E8; padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FBFAF6; border-radius:12px; overflow:hidden; border:1px solid #E1DDD0;">
                    <tr>
                        <td style="background-color:#205557; padding:22px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td>
                                        <img src="{{ asset('brand/alima_logo_cream@2x.png') }}" width="120" height="36" alt="Alima" style="display:block; border:0; width:120px; height:36px;">
                                    </td>
                                    <td align="right" style="font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#D2E842; text-transform:uppercase;">
                                        Registration #{{ str_pad((string) $interest->id, 3, '0', STR_PAD_LEFT) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Ink hero: who, and the clock we're on. --}}
                    <tr>
                        <td style="background-color:#181723; padding:36px 32px 32px;">
                            <p style="margin:0 0 16px; font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#D2E842; text-transform:uppercase;">
                                &#9632;&nbsp; New registration
                            </p>
                            <h1 style="margin:0 0 10px; font-family:{!! $sans !!}; font-size:34px; line-height:1.1; letter-spacing:-0.02em; color:#F5F2E8; font-weight:600;">
                                {{ $interest->company_name }} <span style="color:#D2E842;">wants in.</span>
                            </h1>
                            <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#D9D6CC;">
                                {{ $interest->name }}{{ $interest->location ? ' · '.$interest->location : '' }}
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #3A3946;">
                                <tr>
                                    <td style="padding-top:16px;">
                                        <p style="margin:0 0 2px; font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#AEACB5; text-transform:uppercase;">Reply by · UK time</p>
                                        <p style="margin:0; font-family:{!! $sans !!}; font-size:26px; line-height:1.2; color:#D2E842; font-weight:600;">{{ $replyBy->format('D j M, H:i') }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Spec rows. --}}
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            @foreach ($sections as $heading => $rows)
                                <p style="margin:0 0 6px; font-family:{!! $mono !!}; font-size:11px; letter-spacing:0.08em; color:#5F5D6B; text-transform:uppercase;">{{ $heading }}</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px; border-top:1.5px solid #181723;">
                                    @foreach (array_filter($rows) as $label => $value)
                                        <tr>
                                            <td width="120" valign="top" style="padding:11px 12px 11px 0; border-bottom:1px solid #E1DDD0; font-family:{!! $mono !!}; font-size:12px; color:#5F5D6B;">{{ $label }}</td>
                                            <td valign="top" style="padding:11px 0; border-bottom:1px solid #E1DDD0; font-size:14px; line-height:1.5; color:#181723; word-break:break-word;">{!! $value !!}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endforeach
                        </td>
                    </tr>

                    {{-- Actions. --}}
                    <tr>
                        <td style="padding:0 32px 36px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color:#181723; border-radius:999px;">
                                        <a href="{{ $url }}" style="display:inline-block; padding:12px 24px; font-family:{!! $sans !!}; font-size:14px; font-weight:600; color:#F5F2E8; text-decoration:none;">Open in console &rarr;</a>
                                    </td>
                                    <td width="12">&nbsp;</td>
                                    <td style="border:1.5px solid #181723; border-radius:999px;">
                                        <a href="mailto:{{ $interest->email }}?subject={{ rawurlencode('Welcome to Alima, '.$firstName) }}" style="display:inline-block; padding:10.5px 22px; font-family:{!! $sans !!}; font-size:14px; font-weight:600; color:#181723; text-decoration:none;">Email {{ $firstName }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:16px 0 0; font-size:13px; line-height:1.6; color:#4A4857;">
                                Or just hit reply — it goes straight to {{ $firstName }}.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#6B6977;">
                                &copy; {{ now()->year }} Alima. Marketing brighter together. Sent to the registrations team.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
