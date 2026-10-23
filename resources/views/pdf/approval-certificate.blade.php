@php
    /*
     * Approval certificate (Step M-6.3) — the "Studio Paper" language shared with the invoice, brief
     * and report PDFs: Cloud Cream paper, a Midnight Ink band with the master mark on a Deep Teal
     * tile, Poppins SemiBold headline, DM Mono spec labels and ruled approval rows. It records who
     * signed off the post, when, the approval policy, and the content hash of the approved revision.
     */
    use App\Support\Pdf\PdfBrand;

    $logo = PdfBrand::markTile();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Approval certificate — {{ $client->name }}</title>
    <style>
        @include('pdf.partials.brand-fonts')

        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Poppins', sans-serif; color: #141B2D; background: #FBF7EF; }
        .sheet { padding: 48px 56px; }
        .band { background: #141B2D; color: #FBF7EF; border-radius: 18px; padding: 28px 32px; margin-bottom: 36px; }
        .mark { width: 44px; height: 44px; vertical-align: middle; }
        .eyebrow { font-family: 'DM Mono', monospace; text-transform: uppercase; letter-spacing: 0.14em; font-size: 10px; color: #9FB3B0; }
        h1 { font-size: 30px; font-weight: 600; margin: 14px 0 4px; letter-spacing: -0.02em; }
        h1 em { font-style: normal; color: #1F6F6B; }
        .lede { color: #45515F; font-size: 13px; margin: 0; }
        .spec { font-family: 'DM Mono', monospace; text-transform: uppercase; letter-spacing: 0.12em; font-size: 9px; color: #6B7682; margin-bottom: 4px; }
        .grid { width: 100%; margin-bottom: 32px; }
        .grid td { width: 50%; vertical-align: top; padding-right: 18px; font-size: 13px; }
        .rows { width: 100%; border-collapse: collapse; }
        .rows th { text-align: left; font-family: 'DM Mono', monospace; text-transform: uppercase; letter-spacing: 0.1em; font-size: 9px; color: #6B7682; border-bottom: 1px solid #141B2D; padding: 0 0 8px; }
        .rows td { padding: 12px 0; border-bottom: 1px solid #E4DCCB; font-size: 13px; vertical-align: top; }
        .approver { font-weight: 600; }
        .approver small { display: block; font-weight: 400; color: #6B7682; font-family: 'DM Mono', monospace; font-size: 10px; }
        .pill { display: inline-block; background: #E3F0EE; color: #1F6F6B; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 600; }
        .hash { margin-top: 28px; padding: 18px 20px; background: #F2ECDE; border-radius: 14px; }
        .hash code { font-family: 'DM Mono', monospace; font-size: 11px; color: #141B2D; word-break: break-all; }
        .foot { margin-top: 36px; font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: #9AA4AE; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="band">
            <img class="mark" src="{{ $logo }}" alt="">
            <div class="eyebrow" style="margin-top:16px;">Approval certificate · {{ $organization->name }}</div>
            <h1>Signed off<em>.</em></h1>
            <p class="lede">A record of the client sign-off for this post.</p>
        </div>

        <table class="grid">
            <tr>
                <td>
                    <div class="spec">Client</div>
                    {{ $client->name }}
                </td>
                <td>
                    <div class="spec">Approval policy</div>
                    {{ $policy->label() }} — {{ $policy->description() }}
                </td>
            </tr>
            <tr>
                <td style="padding-top:18px;">
                    <div class="spec">Post</div>
                    #{{ $post->id }}@if($post->master_caption) — {{ \Illuminate\Support\Str::limit($post->master_caption, 80) }}@endif
                </td>
                <td style="padding-top:18px;">
                    <div class="spec">Generated</div>
                    {{ $generatedAt->format('j M Y, H:i') }} ({{ $timezone }})
                </td>
            </tr>
        </table>

        <div class="spec">Sign-off</div>
        <table class="rows">
            <thead>
                <tr><th>Approver</th><th>Decision</th><th>When</th></tr>
            </thead>
            <tbody>
                @forelse($decisions as $decision)
                    <tr>
                        <td class="approver">{{ $decision['approver'] }}@if($decision['email'])<small>{{ $decision['email'] }}</small>@endif</td>
                        <td><span class="pill">{{ $decision['decision'] }}</span></td>
                        <td>{{ $decision['decided_at'] }} ({{ $timezone }})</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Approved by the client.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if($contentHash)
            <div class="hash">
                <div class="spec">Approved content — SHA-256 of revision #{{ $revision->id }}</div>
                <code>{{ $contentHash }}</code>
            </div>
        @endif

        <div class="foot">Generated by {{ $organization->name }} · Alima</div>
    </div>
</body>
</html>
