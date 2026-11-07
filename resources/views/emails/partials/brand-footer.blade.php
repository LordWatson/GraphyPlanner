{{--
    Step GTM-3.1 — the footer line on a client-facing email. A white-label agency's own line
    replaces the Alima credit; with nothing written, the "don't reply" note stands on its own,
    because the credit is the thing the agency is paying to remove.
--}}
<tr>
    <td style="padding:20px 32px; background-color:#F5F2E8; border-top:1px solid #E1DDD0;">
        <p style="margin:0; font-size:12px; color:#6B6977;">
            @if ($branding->isWhiteLabel)
                {{ $branding->footer ? $branding->footer.' ' : '' }}This is an automated message, please don't reply directly to this email.
            @else
                &copy; {{ now()->year }} Alima. Marketing brighter together. This is an automated message, please don't reply
                directly to this email.
            @endif
        </p>
    </td>
</tr>
