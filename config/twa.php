<?php

/*
|--------------------------------------------------------------------------
| Trusted Web Activity (Phase GTM-6)
|--------------------------------------------------------------------------
|
| The Android app on Google Play is a thin TWA wrapper around this PWA, not a second codebase
| (CG-8.1's "PWA, TWA or native" decided in favour of TWA — see `business-workspaces.md`).
|
| Android only treats the wrapper as trusted — no browser chrome, and the PWA's own push
| subscription intact — when `/.well-known/assetlinks.json` on **this** domain names the app's
| package and signing-certificate fingerprint. Both come from the Play Console once the app is
| uploaded ("Setup → App integrity → App signing"), so until they are set the route 404s rather
| than serving a half-filled file that would fail verification silently.
|
| `ANDROID_FINGERPRINTS` takes one or more colon-separated SHA-256 fingerprints, comma-separated.
| In practice there are two: Play's app-signing key and your upload key.
|
*/

return [

    'package' => env('ANDROID_PACKAGE'),

    'fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANDROID_FINGERPRINTS', '')),
    ))),

];
