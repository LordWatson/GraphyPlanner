# Mobile (Capacitor native shell)

## Purpose

An iOS + Android wrapper around the hosted app, built with Capacitor 8 in **remote-URL mode**: the
WebView loads the live Inertia app from `server.url`, so there is no second frontend, no API-token
auth and no bundled pages. The shell adds what a browser tab can't do reliably on a phone: native
push (FCM), camera / gallery capture, the OS share sheet for Story files, and app links.

The web app is unchanged in a browser — every native path is gated on
`Capacitor.isNativePlatform()` and the plugin code is only fetched inside the shell.

**Relationship to the TWA (Step GTM-6.1).** `android/` is still the Play Trusted Web Activity
(`android/README.md`). The Capacitor projects live in `native/` and use the **same package id**
(`com.alima.app`), so on Google Play the Capacitor build would _replace_ the TWA listing rather than
sit beside it — that is an owner decision, not made here (see Known Gaps).

## Layout

| Thing                                    | Where                                                        |
| ---------------------------------------- | ------------------------------------------------------------ |
| Shell config (app id, server URL, paths) | `capacitor.config.ts`                                        |
| Android project                          | `native/android` (committed)                                 |
| iOS project (SPM, no CocoaPods)          | `native/ios` (committed, **never built** — no Mac)           |
| Offline / error page                     | `native/www` — built from `public/offline.html`, git-ignored |
| Icon + splash source                     | `native/assets/logo.svg` (the cream mark on Deep Teal)       |
| Frontend glue                            | `resources/js/lib/native-{shell,push,media,share}.ts`        |

Commands: `npm run native:sync` (build `native/www`, then `cap sync`), `npm run native:android`,
`npm run native:ios`. `CAPACITOR_SERVER_URL` chooses the app the shell opens and is baked in at sync
time — default `https://app.alima.com`; for a dev build use your LAN URL
(`CAPACITOR_SERVER_URL=http://192.168.x.x:8000 npm run native:sync`; `http://` turns cleartext on).
Regenerate icons/splash with `npx @capacitor/assets generate --assetPath native/assets
--androidProject native/android --iosProject native/ios/App --iconBackgroundColor "#205557"
--splashBackgroundColor "#205557" --splashBackgroundColorDark "#181723"`.

## Models / Tables

- `PushSubscription` (`push_subscriptions`) — a phone's FCM device token is a row here, beside the
  browser subscriptions: `platform` is `android` / `ios` (null = browser), the token is in
  `endpoint` (unique by `endpoint_hash`), `public_key` / `auth_token` are null, and `updated_at` is
  "last seen". One table so every push sender keeps looping one relation. See `notifications.md`.

## Actions / Services / Contracts

- `App\Services\Push\FcmPushNotifier` — the bound `App\Contracts\PushNotifier`. Sends native rows
  over FCM HTTP v1 and passes browser rows to the web transport it wraps. Details in
  `notifications.md` ("Native push").
- `App\Actions\Push\RegisterPushDeviceAction` — upsert a device token for the current user.
- `App\Listeners\ForgetPushDeviceOnLogout` — deletes the device row registered in this session.
- Frontend:
    - `lib/native-shell.ts` — `initNativeShell()` (called from `app.tsx` before Inertia boots):
      installs the media picker, opens tapped notifications and app links (same origin only), and
      re-registers the device token when a user who opted in on this phone is signed in.
    - `lib/native-push.ts` — permission prompt (only when the toggle is turned on), token
      registration/refresh/removal. The opt-in is remembered per user in `localStorage`
      (`alima.native-push`), so a second person on the same phone is never subscribed silently.
      `hooks/use-push.ts` branches to it, so `PushRemindersCard` is the one opt-in UI.
    - `lib/native-media.ts` — a capture-phase click listener takes over every
      `<input type="file">` whose `accept` includes `image/` or `video/`: native action sheet
      (take photo / record video / choose from library) → `File`s → set on the input → `change`.
      Pages and upload endpoints are untouched; CSV / PDF inputs keep the system chooser.
    - `lib/native-share.ts` — Story handoff: writes the file to the app cache and opens the OS
      share sheet (`lib/story-share.ts` branches to it; an Android WebView has no `navigator.share`).

## Routes / Controllers

- `POST /push/devices` → `PushDeviceController@store` (`push.devices.store`) — register / refresh.
- `DELETE /push/devices` → `PushDeviceController@destroy` (`push.devices.destroy`) — opt out.
  Both sit in the `org.member` + `portal.redirect` group beside `push.subscribe`.
- `GET /.well-known/apple-app-site-association` — iOS universal links; 404 until `IOS_APP_ID`.
- `GET /.well-known/assetlinks.json` (existing, `config/twa.php`) — also verifies the Capacitor
  Android build's App Links.

## Business Rules

- Same opt-in rules as web push: any member of an agency or business workspace, never a client
  reviewer (redirected to the portal); a user only ever adds or removes their own token.
- Permissions are asked at the moment of use, never at launch: notifications when the reminders
  toggle is turned on; camera / microphone / photos when that source is picked.
- Android declares only `INTERNET` and `POST_NOTIFICATIONS` — capture uses the system camera app
  and the Photo Picker, which need no permission. iOS usage strings are in `Info.plist`.
- Platform defaults relied on (no code): the Android back button walks WebView history
  (`@capacitor/app`), links to other hosts open in the system browser, and safe areas are kept
  clear because the page never opts into `viewport-fit=cover`.
- Secrets never committed (`.gitignore`): `native/android/app/google-services.json`,
  `native/ios/App/App/GoogleService-Info.plist`, keystores, the FCM service-account JSON.

## Tests

- `tests/Feature/Push/PushDeviceTest` — register, refresh + ownership move, validation, guest,
  client reviewer, 404 when off, delete only your own, logout cleanup, shared prop, one sender
  reaching a browser and a phone.
- `tests/Unit/Services/Push/FcmPushNotifierTest` — FCM payload + bearer token, token caching,
  `UNREGISTERED` → `Gone`, 5xx / 401 / exception → `Failed`, log transport, web fall-through.
- `tests/Feature/Mobile/AppLinksTest` — the Apple association file.
- `resources/js/lib/native-media.test.ts` — which inputs are taken over, camera / library / bridge
  fallback → `File`s, cancel.

## Known Gaps / Open Questions

- **Android: built and smoke-tested only.** `gradlew assembleDebug` succeeds and, on an API 36
  emulator against a local server, the shell opens the sign-in page, the bridge loads, login works
  and the system bars stay clear of the page. Not exercised: push (needs Firebase), the camera /
  gallery sheet, Story share, the back button, app links, the offline page.
- **iOS: never built** (no Mac).
- **iOS push needs the Firebase SDK.** `@capacitor/push-notifications` returns an APNs token on iOS
  unless Firebase Messaging is added to the Xcode project (SPM) and `AppDelegate` forwards the FCM
  token. Until that is done on a Mac, an iOS token would be rejected by FCM — do not ship iOS push.
- `App.entitlements` sets `aps-environment` to `development`; release signing must use `production`.
- **Play listing:** Capacitor build vs. the TWA under the same package id — decide which ships.
  They cannot both be live, and the signing key must be the one Play already knows.
- **Apple review:** a remote-URL wrapper can be rejected as "minimum functionality"; native push,
  capture and share are the case for it.
- OAuth connect flows open in the system browser and return via app links — unverified.
- Large videos picked on iOS go through the bridge as base64 (see the `shortcut:` note in
  `native-media.ts`).
- "Copy link" buttons were left as clipboard copies; only the Story file share uses the share sheet.

## Owner handoff checklist

1. Android toolchain: JDK 21 + SDK 36 (set `JAVA_HOME` / `ANDROID_HOME`); `npm run native:sync`;
   `gradlew assembleDebug` in `native/android`; test push, capture and share on a real device.
2. Firebase project → add Android app `com.alima.app` → `google-services.json` into
   `native/android/app/`. Add the iOS app → `GoogleService-Info.plist`, Firebase Messaging via SPM,
   forward the FCM token in `AppDelegate`; upload the APNs auth key to Firebase.
3. Server env: `PUSH_ENABLED=true`, `PUSH_FCM_TRANSPORT=fcm`,
   `PUSH_FCM_CREDENTIALS=/absolute/path/service-account.json` (outside the repo).
4. App links: `ANDROID_PACKAGE` + `ANDROID_FINGERPRINTS` (existing), `IOS_APP_ID=<TeamID>.com.alima.app`.
5. On a Mac: open `native/ios`, set the team, enable Push Notifications + Associated Domains, build.
6. Signing keys and store submission.

## Changelog

- 2026-11-17: Created. Capacitor 8 shell in remote-URL mode (`native/`), native push over FCM
  behind the existing `PushNotifier` contract, camera / gallery picker for media inputs, native
  Story share, app links, icons and splash. Verified: PHP + Vitest suites, `tsc`, lint, guards,
  `cap sync`, Android debug build, login on an emulator. Not verified: iOS, push, capture, share.
