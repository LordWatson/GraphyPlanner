<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $connected ? 'Account connected' : 'Connection failed' }}</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #0b0b0c;
            color: #f5f5f5;
        }
        .card {
            text-align: center;
            padding: 2rem;
        }
        a {
            color: #7dd3fc;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $connected ? 'Account connected!' : 'Something went wrong' }}</h1>
        <p>
            @if ($connected)
                You can close this window and return to AlimaPlanner.
            @else
                We couldn't find the account you were connecting. You can close this window and try again from AlimaPlanner.
            @endif
        </p>
        @if ($clientUrl)
            <p><a href="{{ $clientUrl }}">Go back to AlimaPlanner</a></p>
        @endif
    </div>
    <script>
        (function () {
            try {
                if (window.opener) {
                    window.opener.postMessage({ type: 'graphy:social-account-connected', connected: @json($connected) }, window.location.origin);
                }
            } catch (e) {
                // Ignore postMessage failures (e.g. cross-origin opener) — the polling fallback
                // in the opener still picks up the status change once this tab closes.
            }

            setTimeout(function () {
                window.close();
            }, 1200);
        })();
    </script>
</body>
</html>
