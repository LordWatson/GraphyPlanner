<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security headers (Step I-16.2 / M-1.2)
    |--------------------------------------------------------------------------
    |
    | `SecurityHeaders` sets these on every response. The Content-Security-Policy uses a per-request
    | nonce for scripts (Vite's tags and the inline theme script carry it), so no inline script
    | runs unless we wrote it. Styles allow 'unsafe-inline' because React `style` attributes,
    | Radix and Recharts set inline styles.
    |
    | `report_only` switches the CSP header to `Content-Security-Policy-Report-Only` — use it to
    | trial a tighter policy in production before enforcing it. `report_uri` collects violations
    | (Sentry gives each project a CSP report endpoint).
    |
    | `except` lists paths that get every header except the CSP: the Horizon and Pulse dashboards
    | (super-admin only) ship their own inline scripts.
    |
    */

    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),
        'except' => ['horizon', 'horizon/*', 'pulse', 'pulse/*', 'livewire/*'],
    ],

    'hsts' => [
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],

    'referrer_policy' => 'strict-origin-when-cross-origin',

    // Features the app never uses. Camera/microphone stay off until a feature needs them.
    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()',

    /*
    |--------------------------------------------------------------------------
    | Upload hardening
    |--------------------------------------------------------------------------
    |
    | `virus_scanner`: `null` (no scan) or `clamav` (clamd's INSTREAM over TCP). With ClamAV, an
    | unreachable daemon rejects the upload when `fail_closed` is true, so a scanner outage can't
    | let unscanned files through; set it false to accept files while the scanner is down.
    |
    */

    'uploads' => [
        'virus_scanner' => env('UPLOAD_VIRUS_SCANNER', 'null'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
            'fail_closed' => (bool) env('CLAMAV_FAIL_CLOSED', true),
        ],
    ],

];
