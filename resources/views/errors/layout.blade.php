{{--
    Alima error page (brand book chapter-opener style): a full Ink spread with the running head,
    the status code as a giant Lime numeral, a voice-led headline + one line, a Lime CTA home and a
    cream "go back", and the cream panda peeking up from the bottom edge.

    Deliberately self-contained — inline CSS, static `public/brand` artwork, no Vite bundle — so it
    still renders when the app itself is what broke.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('code') — @yield('title') · Alima Planner</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @fonts
        <style>
            *, *::before, *::after { box-sizing: border-box; }
            html, body { margin: 0; min-height: 100%; background: #181723; }
            body {
                color: #F5F2E8;
                font-family: 'Poppins', Arial, ui-sans-serif, system-ui, sans-serif;
                -webkit-font-smoothing: antialiased;
                min-height: 100svh;
                display: flex;
                flex-direction: column;
                position: relative;
                overflow: hidden;
            }
            .mono {
                font-family: 'DM Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 11px;
                line-height: 16px;
                font-weight: 500;
                letter-spacing: 0.1em;
                text-transform: uppercase;
            }
            .page { position: relative; z-index: 1; width: 100%; max-width: 1200px; margin: 0 auto; padding: 32px 20px 0; display: flex; flex-direction: column; flex: 1; }
            .head { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding-bottom: 10px; border-bottom: 1px solid rgb(245 242 232 / 0.25); color: rgb(245 242 232 / 0.7); }
            .head a { color: inherit; text-decoration: none; }
            .head a:hover { color: #F5F2E8; }
            .head img { height: 28px; width: auto; display: block; }
            .body { display: grid; gap: 28px; padding: 56px 0 300px; max-width: 760px; }
            .code { font-weight: 700; font-size: clamp(7rem, 22vw, 13rem); line-height: 0.82; letter-spacing: -0.05em; color: #D2E842; font-variant-numeric: tabular-nums; margin: 0; }
            h1 { font-weight: 600; font-size: clamp(2.4rem, 5.5vw, 4rem); line-height: 1.02; letter-spacing: -0.02em; margin: 0; text-wrap: balance; }
            .dash { display: block; width: 48px; height: 4px; border-radius: 999px; background: #F03D2A; }
            p.lead { margin: 0; max-width: 34rem; font-size: 16px; line-height: 1.6; color: rgb(245 242 232 / 0.72); text-wrap: pretty; }
            .actions { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 4px; }
            .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 48px; padding: 0 26px; border-radius: 999px; font: inherit; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; transition: background-color 150ms cubic-bezier(0.2, 0.8, 0.2, 1), border-color 150ms cubic-bezier(0.2, 0.8, 0.2, 1); }
            .btn:focus-visible { outline: 3px solid #6FA4D8; outline-offset: 2px; }
            .btn-spark { background: #D2E842; color: #181723; border: 0; }
            .btn-spark:hover { background: #E0EC84; }
            .btn-on-ink { background: transparent; color: #F5F2E8; border: 1.5px solid rgb(245 242 232 / 0.45); }
            .btn-on-ink:hover { border-color: #F5F2E8; background: rgb(245 242 232 / 0.1); }
            .peek { position: absolute; z-index: 0; right: -2%; bottom: 0; width: min(46vw, 460px); transform: translateY(36%); pointer-events: none; user-select: none; }
            @media (min-width: 768px) {
                .page { padding: 48px 48px 0; }
                .body { padding-top: 80px; padding-bottom: 120px; }
            }
            @media (max-width: 767px) {
                .peek { width: 70vw; right: 50%; transform: translate(50%, 40%); }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <header class="head mono">
                <a href="/" aria-label="Alima home"><img src="/brand/alima_logo_cream.svg" alt="Alima"></a>
                <span>Error @yield('code')</span>
            </header>

            <main class="body">
                <p class="code" aria-hidden="true">@yield('code')</p>
                <h1>@yield('headline')</h1>
                <span class="dash" aria-hidden="true"></span>
                <p class="lead">@yield('message')</p>
                <div class="actions">
                    <a class="btn btn-spark" href="{{ auth()->check() ? url('/dashboard') : url('/') }}">
                        {{ auth()->check() ? 'Back to the studio' : 'Back to Alima' }} &rarr;
                    </a>
                    <button type="button" class="btn btn-on-ink" onclick="history.length > 1 ? history.back() : (location.href = '/')">Go back</button>
                </div>
            </main>
        </div>

        <img class="peek" src="/brand/alima_mark_cream.svg" alt="" aria-hidden="true">
    </body>
</html>
