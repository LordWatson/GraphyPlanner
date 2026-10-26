# SocialAccount

## Purpose
Covers Step 0.6: the social media accounts (per platform) a client posts to — their timezone,
locale, default posting windows and persona override, plus vendor connection state. This is the
account-level record `Post`/`PostTarget` (Step 0.8+) and Phase 1's publish adapter will target; no
vendor is wired up yet.

## Models / Tables
- `SocialAccount` (`social_accounts`): `org_id`, `client_id` (FKs, cascade on delete), `platform`
  (enum: instagram/tiktok/facebook/linkedin), `handle`, `display_name`, `timezone` (required IANA
  string, validated against `DateTimeZone::listIdentifiers()` via Laravel's `timezone` rule),
  `language`, `country`, `default_location`, `posting_windows` (json array of
  `{day, start, end}`), `persona_override` (text, overrides `BrandBrain` persona for this account),
  `connection_status` (enum: not_connected/connected/token_expired, default `not_connected`).
  Publisher/vendor fields present but unused until Phase 1: `provider`, `external_profile_id`,
  `external_account_id`, `connected_at`. Step 1.9.2 added `meta_access_token` (`encrypted` cast,
  never stored/logged in plaintext), `meta_access_token_expires_at`, `meta_instagram_user_id` —
  populated only for Instagram accounts via the additive Facebook Login connect flow (Step 1.9.3),
  purely to grant Meta Graph API access for Instagram audio search (Step 1.9.4); they never affect
  the existing Upload-Post `connection_status`/`external_profile_id` fields above. `belongsTo`
  `Organization`/`Client`; `Client::socialAccounts(): HasMany`.
- The exact platform list and `postingWindows[]`/`personaOverride` shapes weren't specified beyond
  names in the plan; platforms were taken from the Phase 1 rollout order (§1.7: LinkedIn, Facebook,
  Instagram, TikTok), and `posting_windows` uses a day/start/end shape as an interim definition —
  revisit if the original spec surfaces later.

## Actions / Services / Contracts
- `App\Actions\SocialAccounts\CreateSocialAccountAction`: creates a `SocialAccount` scoped to a
  `Client` (sets `org_id`/`client_id`), wrapped in `DB::transaction`, logs the creation.
- `App\Actions\SocialAccounts\UpdateSocialAccountAction`: updates an existing `SocialAccount`,
  wrapped in `DB::transaction`, logs the update.
- `App\Actions\SocialAccounts\ConnectManagedAccountAction` (Step B-5.2): the business shell's one-tap
  connect — find-or-create the primary brand's account for a given `Platform` (defaults: handle from
  the brand name, timezone/language/country from the brand/org) in a transaction, then hand back the
  hosted connect URL via `ConnectSocialAccountAction`. Managed credentials/profile are resolved
  through `App\Contracts\PublishCredentials` (see `.junie/modules/publishing.md`); the business user
  never types an API key. Route: `POST /accounts/connect/{platform}`
  (`business.accounts.connect-platform` → `Business\AccountController::connectPlatform`).
- `App\Policies\SocialAccountPolicy`: `viewAny(user, client)`/`view` mirror `ClientPolicy`
  (Owner/Strategist/Designer/Viewer in-org, or the scoped Client reviewer); `create(user, client)`,
  `update(user, socialAccount)`, `delete(user, socialAccount)` restricted to Owner/Strategist in the
  same org. Registered via `Gate::policy` in `AppServiceProvider`.
- `App\Support\Options\TimezoneOptions`: all `DateTimeZone::listIdentifiers()` as a `{value, label}`
  list, used to populate the `timezone` dropdown on the client page's inline "Add social account"
  form (passed as `ClientController::show`'s `timezones` prop).
- `App\Actions\SocialAccounts\CheckSocialAccountHealthAction` (Step 1.6): calls the bound
  `PublishAdapter::health()` for a single `connected` `SocialAccount` and flips it to
  `token_expired` when unhealthy; `not_connected`/already-`token_expired` accounts are left alone
  (only the Step 1.3 connect flow clears `token_expired`).
- `App\Actions\SocialAccounts\CheckAllSocialAccountsHealthAction` (Step 1.6): sweeps every
  `connected` `SocialAccount` in the DB through `CheckSocialAccountHealthAction`, returning the
  count flagged as expired.
- `App\Actions\SocialAccounts\ConnectMetaAudioAccessAction` (Step 1.9.3): persists the Meta user
  access token/expiry/Instagram Business user id obtained via the Facebook Login connect flow onto
  a `SocialAccount`, wrapped in `DB::transaction()`; logs only the social account id, never the
  token. Purely additive — never touches `connection_status`/`external_profile_id`.

## Console Commands
- `App\Console\Commands\CheckSocialAccountsHealthCommand` (`social-accounts:check-health`, Step
  1.6): runs `CheckAllSocialAccountsHealthAction` against the bound `PublishAdapter`. Scheduled
  hourly in `routes/console.php`, mirroring Step 1.5's `publishing:poll-pending-statuses` pattern.

## Phase M-2 additions

- **Columns**: `platform_settings` (json, registry `settings` keyed by vendor field),
  `last_health_check_at`, `last_health_error`, `token_expiry_warning` (`7d` / `1d` / `expired`).
- **New platforms (M-2.6)**: YouTube, Google Business, Threads, Pinterest, X — creation only for
  enabled platforms (`StoreSocialAccountRequest`), settings validated against the registry
  (`ValidatesPlatformSettings`), Accounts tab shows a settings dialog and missing-settings warning.
- **Token lifecycle (M-2.7)**: `CheckTokenExpiryAction` (runs inside every health check and for
  unconnected accounts with a token) refreshes the Meta token in its last 7 days
  (`MetaTokenService::refresh()`, also used by the connect controller), else warns once at 7 days and
  once at 1 day (`SocialAccountTokenExpiring` to Owners/Strategists), and stores the stage — which
  puts the account on Home and makes client health Amber. `CheckSocialAccountHealthAction` records
  `last_health_check_at` / `last_health_error`. A successful reconnect (`UploadPostAdapter::handleCallback()`)
  clears any stale `last_health_error` / `token_expiry_warning` as it flips the account back to
  `connected`, so the Accounts tab never shows the red "no longer accepts this connection" line
  next to a green pill while waiting for the next hourly sweep to overwrite it.
- **Client self-connect (M-2.8)**: `connect_invites` (`ConnectInvite`: hashed token, optional email,
  allowed account ids / platforms, expiry (7d default), revoke, `used_at`, `connected_account_ids`).
  `CreateConnectInviteAction` (raw token returned + emailed once via `ClientConnectInviteMail`),
  `RevokeConnectInviteAction`, `RecordClientConnectionAction` (records on the invite, notifies the
  client's owner or Owners/Strategists with `ClientConnectedAccount`; health refreshes on its own).
  `ConnectStateStore::issue()` carries `connect_invite_id` / `via` to the callback.
  Routes: `POST /clients/{client}/connect-invites`, `DELETE /connect-invites/{connectInvite}`
  (`ConnectInviteController`), public `GET /connect/{token}` + `POST /connect/{token}/accounts/
  {socialAccount}` (`PublicConnectController`, throttled), portal
  `POST /portal/accounts/{socialAccount}/connect` (`PortalAccountConnectController`,
  `SocialAccountPolicy::connectAsClient`). Pages: `connect/show`, Accounts tab invite panel.

## Routes / Controllers
- `POST /clients/{client}/social-accounts` → `clients.social-accounts.store`
  (`SocialAccountController::store`) — validated via `StoreSocialAccountRequest`, delegates to
  `CreateSocialAccountAction`.
- `PUT /social-accounts/{socialAccount}` → `social-accounts.update`
  (`SocialAccountController::update`) — validated via `UpdateSocialAccountRequest`, delegates to
  `UpdateSocialAccountAction`.
- `DELETE /social-accounts/{socialAccount}` → `social-accounts.destroy`
  (`SocialAccountController::destroy`).
- `POST /social-accounts/{socialAccount}/connect` → `social-accounts.connect`
  (`SocialAccountController::connect`, Step 1.3, bugfix) — authorizes `update`, delegates to
  `App\Actions\SocialAccounts\ConnectSocialAccountAction` (calls the bound `PublishAdapter::connectAccount()`),
  and returns the vendor's connect URL as **JSON** (`{url}`) rather than redirecting the current tab
  there. The client show page opens that URL in a separate popup/tab (`handleConnect` in
  `resources/js/pages/clients/show.tsx`) so the user's Graphy tab stays open.
- `GET /social-accounts/callback` → `social-accounts.callback` (`SocialAccountController::callback`,
  Step 1.3, bugfix) — unauthenticated (the vendor redirects the end user's browser here directly,
  like the client review portal), calls `PublishAdapter::handleCallback()` with the query string,
  then renders the standalone `resources/views/social-accounts/connected.blade.php` view (shown
  inside the popup/tab), which notifies the opening Graphy tab via `postMessage` and auto-closes
  itself. See `publishing.md` for the full flow.
- `GET /clients/{client}/social-accounts/{socialAccount}/facebook/connect` →
  `social-accounts.facebook.connect` and `GET .../facebook/callback` →
  `social-accounts.facebook.callback` (`App\Http\Controllers\Auth\FacebookInstagramConnectController`,
  Step 1.9.3) — a second, additive OAuth connect flow (authorizes `update`, same as the Upload-Post
  connect flow above) that grants the Meta Graph API scope (`instagram_basic`,
  `instagram_content_publish`) needed for Instagram audio search (`publishing.md`'s
  `MetaGraphMusicProvider`, Step 1.9.4). A thin, direct `Http`-based OAuth exchange (no Socialite
  dependency installed) rather than a popup/tab like the Upload-Post flow, since Facebook's OAuth
  redirect returns to the same authenticated browser tab. Does not touch/replace the Upload-Post
  connect flow's `connection_status`/`external_profile_id`.
- `GET /auth/meta/callback` → `auth.meta.callback`
  (`FacebookInstagramConnectController::metaCallback`) — a static alternative to the dynamic
  `.../facebook/callback` route above, needed because Facebook Apps only allow a fixed,
  pre-registered list of OAuth redirect URIs (one static URL, not one per account). Used whenever
  `services.facebook.redirect_uri` (`FACEBOOK_REDIRECT_URI`) is configured; recovers the
  `SocialAccount` from the `state` query parameter `redirect()` sent Facebook (instead of from the
  URL), then shares the same exchange/persist logic (`handleCallback`) as the dynamic callback.
- `ClientController::show` now also passes `socialAccounts` (list, gated by `viewAny`) and
  `can.createSocialAccount` to `clients/show`; rendered as a "Social accounts" tab with an inline
  create form, a "Connect" action (shown when `can.connect` is true and the account isn't already
  `connected`) and a remove action per row, mirroring the invoices tab (no separate index/create/
  edit pages). See the 2026-09-16 Tabs redesign note below.

## Seeders
- `Database\Seeders\DemoContentSeeder`: for each of `ClientSeeder`'s `Recommendo`/`Frzn` clients,
  creates three connected `SocialAccount`s (Instagram/TikTok/LinkedIn) alongside campaigns, assets,
  and posts — see `campaigns.md`/`assets.md`/`posts.md` for the rest of what it seeds. Called from
  `DatabaseSeeder` after `InvoiceSeeder`.

## Business Rules
- `timezone` is required and validated as a real IANA identifier (`timezone:all` rule) — an account
  cannot be created/updated without one.
- Only Owner/Strategist can create/update/delete/connect accounts for a client in their org (`connect`
  reuses the `update` ability); Designer/Viewer can view the list read-only, Client reviewers are
  scoped to their own client (same pattern as `ClientPolicy`/`InvoicePolicy`).
- `connection_status` defaults to `not_connected` and isn't user-editable from the create form — it's
  now flipped to `connected` by the Step 1.3 callback once the vendor connect flow completes; the
  `token_expired` transition is now driven by Step 1.6's `social-accounts:check-health` sweep
  (`CheckSocialAccountHealthAction`), which is the only other writer of `connection_status` besides
  the connect callback.
- Publisher fields `provider`/`external_account_id` remain unused before Phase 1's schedule/webhook
  steps; `external_profile_id`/`connected_at` are now populated by the Step 1.3 callback.

## Tests
- `tests/Feature/SocialAccountControllerTest.php`: Owner can create with a valid IANA timezone;
  creation is rejected without a timezone or with an invalid one; Designer and cross-org users are
  forbidden from creating; Owner can update and delete; Owner can start the connect flow and receives
  the vendor URL as JSON (`Http::fake()`), a vendor rejection returns a JSON error, Designer is
  forbidden from connecting, the vendor callback persists `external_profile_id`/`connection_status`/
  `connected_at` and renders the `social-accounts.connected` view; Client reviewer sees only their
  own client's accounts on the client page.
- `tests/Unit/Actions/SocialAccounts/CheckSocialAccountHealthActionTest.php`,
  `tests/Unit/Actions/SocialAccounts/CheckAllSocialAccountsHealthActionTest.php` (Step 1.6): a
  `connected` account is flipped to `token_expired` when `health()` fails, left `connected` when
  `health()` passes, and `not_connected` accounts are never checked; the sweep only touches
  `connected` accounts.

## Known Gaps / Open Questions
- 2026-10-20: the invite's "activity entry" is the invite row itself (`used_at`,
  `connected_account_ids`) plus a bell notification — there's no client activity feed to write to.
- The exact §4.4 field list/platform set wasn't available in this repo; platforms were inferred from
  the Phase 1 rollout order and `posting_windows`/`persona_override` shapes are interim definitions.
- No dedicated index/create/edit pages — accounts are managed inline on the client show page like
  invoices; revisit if a richer per-account editor becomes necessary later.
- The Step 1.3 callback trusts the `social_account_id`/`profile` query params from the vendor
  redirect with no signature verification (unlike the Step 1.5 webhook, which the plan explicitly
  calls out for signature verification) — revisit once Upload-Post's real callback contract is known.
- The connect popup relies on browser popup permissions; if popups are blocked, `handleConnect` falls
  back to a plain `window.open(url, '_blank')` new tab (still avoids taking over the current tab, but
  loses the `postMessage`/close-polling auto-refresh since there's no reliable window handle) —
  revisit if this proves confusing in practice (e.g. show a manual "Refresh status" affordance).

## Changelog
- 2026-10-20: Phase 18 (grid planner) — `App\Contracts\ProfileFeedSource` reads an Instagram account's recent feed for the grid preview: `MetaGraphProfileFeedSource` uses the Step 1.9 `meta_access_token` (`/{ig-user-id}` + `/media`, cached 15 min per account, never logs the token or media URLs), falling back to `PublishedPostsProfileFeedSource` (posts sent from the app). A grid plan belongs to one Instagram account of its client. Tests: `ProfileFeedSourceTest`.
- 2026-10-20: Phase M-2 — platform settings, new platforms, token lifecycle + health recording, client
  self-connect links. Tests: `SocialAccountTokenLifecycleTest`, `ConnectInviteTest`,
  `NewNetworksPublishTest`.
- 2026-10-18: Step I-1.6 — `SocialAccount` is `#[Hidden(['meta_access_token', 'external_profile_id'])]`.
  Both connect callbacks now require a signed, single-use `state` (`App\Services\ConnectStateStore`,
  cached 10 minutes under a hash, pulled on first use): `ConnectSocialAccountAction` issues one for
  the Upload-Post flow (round-tripped on `redirect_url`; `SocialAccountController::callback` 403s a
  missing/forged/reused/other-account state), and the Facebook Login flow issues one per account +
  user instead of sending the raw account id (both the dynamic and the static `auth/meta/callback`
  reject a state that wasn't issued to this user for this account). Tests:
  `SocialAccountControllerTest::test_a_vendor_callback_without_a_valid_state_is_rejected`,
  `FacebookInstagramConnectControllerTest::test_a_callback_with_a_forged_or_reused_state_is_rejected`,
  `tests/Feature/Security/SecretsAndPrivateMediaTest.php` (no page leaks the token/profile id).
- 2026-10-18: Step I-1.5 — `SocialAccount` uses `SoftDeletes`; deleting an account is a soft delete.
  Post targets that point at a deleted account fail at publish time ("This social account no
  longer exists.") instead of being cascade-deleted.
- 2026-10-12: Bugfix — the Facebook OAuth callback 404'd in any environment with
  `FACEBOOK_REDIRECT_URI` set to a static URL (as Facebook Apps require), since only the dynamic
  `clients/{client}/social-accounts/{socialAccount}/facebook/callback` route existed. Added the
  static `GET /auth/meta/callback` route/`metaCallback` method, which recovers the `SocialAccount`
  from the OAuth `state` parameter instead of the URL.
- 2026-09-25: Step 1.9 — added `meta_access_token`/`meta_access_token_expires_at`/
  `meta_instagram_user_id` columns (Step 1.9.2, `encrypted` cast on the token) and the additive
  `FacebookInstagramConnectController` OAuth connect flow (Step 1.9.3, `ConnectMetaAudioAccessAction`)
  so Instagram accounts can grant the Meta Graph API scope needed for real Instagram audio search.
  See `publishing.md` for the `MetaGraphMusicProvider` this feeds (Step 1.9.4-1.9.6).
- 2026-09-23: Step 1.6 — added `CheckSocialAccountHealthAction`/`CheckAllSocialAccountsHealthAction`
  and the `social-accounts:check-health` command (scheduled hourly) to flip `connected` accounts to
  `token_expired` via `PublishAdapter::health()`. See `clients.md`/`publishing.md` for the
  `ClientHealthService` red-trigger change this feeds into.
- 2026-09-17: The inline "Add social account" form's `timezone` field on `clients/show.tsx` is now a
  `Select` dropdown (all IANA identifiers, from `App\Support\Options\TimezoneOptions`) instead of a
  free-text `Input`.
- 2026-09-16: Bugfix — connecting an account used to `Inertia::location()` the user's current tab to
  Upload-Post's hosted connect page (a full-page takeover with no easy way back to Graphy) and never
  refreshed `connectionStatus` afterward. `connect()` now returns the vendor URL as JSON; the client
  show page opens it in a popup/tab and listens for a `postMessage` (sent by the new
  `social-accounts.connected` view rendered by the callback route) or, as a fallback, polls until that
  popup closes, then reloads the social accounts list. See `publishing.md` for the adapter/controller
  side of this change.
- 2026-09-16: Fixed the connect flow's error/success flashes to use the app-wide
  `Inertia::flash('toast', ['type' => ..., 'message' => ...])` convention (they previously used
  `->with('error'/'success', ...)`, which the frontend's `useFlashToast` hook doesn't read — so a
  vendor rejection silently redirected back to the client page with no visible feedback). Also fixed
  the "Connect"/"Remove" buttons overflowing their row on the client show page: they're now
  icon-only (`size="icon"`, `sr-only` label, `title` tooltip) inside a `shrink-0` wrapper, and the
  row/header content wraps (`flex-wrap`, `min-w-0`) instead of overflowing.
- 2026-09-16: Step 1.3 — added `SocialAccountController::connect`/`callback`,
  `App\Actions\SocialAccounts\ConnectSocialAccountAction`, the `social-accounts.connect` (authed
  POST) and `social-accounts.callback` (public GET) routes, a `can.connect` flag (reuses the
  `update` policy check) on the client page's social account payload, and a "Connect" button on the
  client show page's "Social accounts" tab. See `publishing.md` for the `UploadPostAdapter` changes.
- 2026-09-16: The client show page's "Social accounts" section was converted from a collapsible
  card to a tab inside a `Tabs`/`TabsList`/`TabsContent` layout on
  `resources/js/pages/clients/show.tsx` — see `clients.md`'s changelog for the full redesign note.
- 2026-09-16: Added `Database\Seeders\DemoContentSeeder` (seeds social accounts + campaigns + assets
  + posts/comments/activity logs for the `ClientSeeder` demo clients) and wired it into
  `DatabaseSeeder` after `InvoiceSeeder`.
- 2026-09-08: Social accounts card on the client show page is now collapsible (folded by default),
  matching the "Billing history" section's toggle pattern.
- 2026-09-08: Initial `SocialAccount` model/migration/policy/actions/controller/requests, inline
  create/remove UI on the client show page, factory, and feature tests (Step 0.6).
