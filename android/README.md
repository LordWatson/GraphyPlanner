# Alima on Google Play (Step GTM-6.1)

The Android app is a **Trusted Web Activity**: a thin wrapper around the PWA that already lives at
`/manifest.webmanifest`, not a second codebase. CG-8.1 asked us to choose between PWA, TWA and
native; the decision and its reasoning are recorded in `.junie/modules/business-workspaces.md`.

There is deliberately **no Android source in this repository.** A TWA's Android project is entirely
generated from the config beside this file, and a hand-written copy would be a second thing to keep
in step with the web manifest for no benefit. `twa-manifest.json` is the input; Bubblewrap is the
generator.

## What is in the repo

| Thing                                          | Where                                                          |
| ---------------------------------------------- | -------------------------------------------------------------- |
| The wrapper's configuration                    | `android/twa-manifest.json`                                    |
| The Digital Asset Links file                   | served at `/.well-known/assetlinks.json` from `config/twa.php` |
| The web manifest it wraps                      | `public/manifest.webmanifest`                                  |
| Store listing copy and the data-safety answers | `android/play-listing.md`                                      |

## Building it

```bash
npm install -g @bubblewrap/cli      # once
cd android
bubblewrap init --manifest https://app.alima.com/manifest.webmanifest   # first time only
bubblewrap build                                                        # -> app-release-bundle.aab
```

`bubblewrap init` writes a keystore and the generated Android project into this directory. **Neither
is committed** (see `.gitignore`): the keystore is a signing secret and losing control of it means
losing the ability to publish updates. Keep it in the password manager with the key and store
passwords.

Set `host`, `webManifestUrl`, `iconUrl` and `fullScopeUrl` to the real production domain before the
first build — the values here assume `app.alima.com`.

## Making it trusted (the step people forget)

An unverified TWA still runs, but with a browser address bar across the top, which makes the whole
exercise pointless. Verification needs `/.well-known/assetlinks.json` on the **same domain as
`host`** to name the app's package and signing fingerprints:

1. Upload the `.aab` to the Play Console once.
2. Copy the SHA-256 fingerprints from **Setup → App integrity → App signing**. There are normally
   two: Play's app-signing key and your upload key. Both must be listed.
3. Set `ANDROID_PACKAGE` and `ANDROID_FINGERPRINTS` (comma-separated) in the production env and
   deploy. The route 404s until both are set, rather than serving a file that would fail
   verification quietly.
4. Check it: `https://app.alima.com/.well-known/assetlinks.json` must return the JSON array, and
   Google's statement-list tester must agree.
5. Install the app from Play (not the local `.aab`) and confirm there is **no address bar**.

## Push notifications

Web push (CG-1.3) works inside a TWA through the same service worker and the same VAPID key — there
is no FCM project and no separate token to manage, which is one of the reasons TWA was chosen over
native. It needs:

- `minSdkVersion` 23 or higher (set), because notification permission came in with Android 6;
- Android 13+ asks for the notification permission at runtime, so the first prompt appears when the
  PWA asks, not at install;
- the install to be the **verified** one from Play. In an unverified wrapper the subscription is
  scoped to the Custom Tab and reminders will not arrive.

**This is the one thing that cannot be signed off from here:** push has to be confirmed on a real
device after the listing is live. See the plan's note on what is left.
