@php
    /*
     * Media kit (Step B-8.4) — the brand's one-page kit in the "Studio Paper" look: cream paper, an
     * Ink band with the master mark, Poppins headline, DM Mono spec labels. Drawn only from public
     * kit data (BuildMediaKitAction) — no emails, no drafts, no private figures. A dash means a
     * figure isn't available yet, never a zero.
     */
    use App\Support\Pdf\PdfBrand;

    $logo = PdfBrand::markTile();
    $brand = $kit['brand'] ?? [];
    $audience = $kit['audience'] ?? [];
    $engagement = $kit['engagement'] ?? [];
    $topPosts = $kit['top_posts'] ?? [];
    $count = fn ($value) => $value === null ? '—' : number_format((int) $value);
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $brand['name'] ?? 'Media kit' }} — media kit</title>
    <style>
        @include('pdf.partials.brand-fonts')

        @page { margin: 34px 0 40px 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background-color: #F5F2E8; }
        body { font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.55; color: #181723; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }
        .muted { color: #6B6977; }
        .right { text-align: right; }
        .eyebrow { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 8.5px; letter-spacing: 1.3px; text-transform: uppercase; color: #6B6977; }
        .band { background-color: #181723; color: #F5F2E8; padding: 56px 52px 26px; margin-top: -34px; }
        .band .logo { width: 34px; height: 34px; }
        .band h1 { font-size: 30px; line-height: 1.05; letter-spacing: -0.6px; margin: 18px 0 6px; font-weight: bold; }
        .band .kind { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 9px; letter-spacing: 1.4px; text-transform: uppercase; color: #AEACB5; }
        .wrap { padding: 26px 52px; }
        .bio { font-size: 13px; line-height: 1.7; color: #181723; margin: 0 0 26px; }
        .section { margin-bottom: 26px; }
        .section > .eyebrow { margin-bottom: 10px; display: block; }
        .stat { display: inline-block; width: 32%; }
        .stat .num { font-size: 26px; font-weight: bold; letter-spacing: -0.6px; }
        .stat .lbl { font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; color: #6B6977; }
        .row { border-bottom: 1px solid #E1DDD0; padding: 8px 0; }
        .post { padding: 10px 0; border-bottom: 1px solid #E1DDD0; }
        .post .cap { font-size: 11px; }
        .foot { padding: 14px 52px; color: #AEACB5; font-family: 'DM Mono', 'DejaVu Sans Mono', monospace; font-size: 8px; letter-spacing: 1.2px; }
    </style>
</head>
<body>
    <div class="band">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="Alima">
        @endif
        @if (! empty($brand['kind']))
            <div class="kind" style="margin-top:14px;">{{ $brand['kind'] }}</div>
        @endif
        <h1>{{ $brand['name'] ?? 'Media kit' }}</h1>
        @if (! empty($brand['location']) || ! empty($brand['website']))
            <div class="kind">
                {{ $brand['location'] ?? '' }}{{ ! empty($brand['location']) && ! empty($brand['website']) ? ' · ' : '' }}{{ $brand['website'] ?? '' }}
            </div>
        @endif
    </div>

    <div class="wrap">
        @if (! empty($brand['bio']))
            <p class="bio">{{ $brand['bio'] }}</p>
        @endif

        <div class="section">
            <span class="eyebrow">Audience</span>
            <div>
                <div class="stat">
                    <div class="num">{{ $count($audience['total_followers'] ?? null) }}</div>
                    <div class="lbl">Total followers</div>
                </div>
                <div class="stat">
                    <div class="num">{{ $percent($engagement['engagement_rate'] ?? null) }}</div>
                    <div class="lbl">Engagement rate</div>
                </div>
                <div class="stat">
                    <div class="num">{{ $count($engagement['total_reach'] ?? null) }}</div>
                    <div class="lbl">Reach · {{ $engagement['period_days'] ?? 90 }}d</div>
                </div>
            </div>
        </div>

        @if (! empty($audience['accounts']))
            <div class="section">
                <span class="eyebrow">By platform</span>
                @foreach ($audience['accounts'] as $account)
                    <div class="row">
                        <table>
                            <tr>
                                <td>{{ $account['platform_label'] }} · &#64;{{ $account['handle'] }}</td>
                                <td class="right"><strong>{{ $count($account['followers'] ?? null) }}</strong> followers</td>
                            </tr>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif

        @if (! empty($topPosts))
            <div class="section">
                <span class="eyebrow">Top posts</span>
                @foreach ($topPosts as $post)
                    <div class="post">
                        <table>
                            <tr>
                                <td class="cap">{{ $post['caption'] ?? 'Post' }}</td>
                                <td class="right muted">{{ $count($post['engagement'] ?? null) }} interactions</td>
                            </tr>
                        </table>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="foot">Made with Alima</div>
</body>
</html>
