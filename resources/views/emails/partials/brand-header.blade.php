{{--
    Step GTM-3.1 — the header band on every client-facing email. Alima's cream wordmark on Deep
    Teal by default; on a plan with `Feature::WhiteLabel` the agency's accent colour and, when they
    have uploaded one, their own logo.

    `$brandLogoUrl` is a long-lived signed URL (30 days — see `BrandingResolver`), because an email
    is read days after it is sent. Most clients block remote images by default, so the agency's
    *name* is rendered as the fallback rather than nothing at all.
--}}
<tr>
    <td style="background-color:{{ $branding->accent }}; padding:24px 32px;">
        @if ($brandLogoUrl)
            <img src="{{ $brandLogoUrl }}" height="36" alt="{{ $branding->name }}" style="display:block; border:0; height:36px; max-width:200px;">
        @elseif ($branding->isWhiteLabel)
            <span style="font-family:'Poppins', Arial, sans-serif; color:#F5F2E8; font-size:22px; font-weight:600;">{{ $branding->name }}</span>
        @else
            <img src="{{ asset('brand/alima_logo_cream@2x.png') }}" width="120" height="36" alt="Alima" style="display:block; border:0; width:120px; height:36px;">
        @endif
    </td>
</tr>
