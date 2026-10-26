# Graphy — Development Steps for AI-Assisted Build (Junie)

Adapted from the original developer specification with three corrections:

- **Backend/language**: PHP / Laravel (not Node or Python)
- **Frontend**: Laravel + Inertia + React, built from scratch (not Lovable/React — no UI currently exists)
- **Everything else** (data model, publish adapter pattern, checklist rules, phases) is unchanged from the source spec

Suggested stack to match this, for consistency with your other Laravel/React/Inertia projects:

| Layer | Choice                                                          |
|---|-----------------------------------------------------------------|
| Framework | Laravel 13                                                      |
| Frontend | Inertia.js + React                                              |
| Database | MySQL 8 (tests on in-memory SQLite)                              |
| Auth | Laravel Fortify or Breeze (email/password) + Socialite (Google) |
| Media | S3-compatible storage via Laravel's filesystem disk             |
| Jobs/Queue | Laravel Queues (database or Redis driver)                       |
| Testing | PHPUnit (class-based, `Tests\TestCase`)                         |
| Secrets | `.env`, never exposed to the frontend bundle                    |

Each step below is scoped to be handed to an AI coding agent (Junie) as a single unit of work — a clear goal, the files/models it touches, and a definition of done. Steps within a phase are ordered; phases are meant to be completed and demoable in sequence, matching the original spec's phased delivery (Section 11).

---

## Phase 0 — Operate Without Publish

Goal: real clients, brand brains, assets, dual-timezone editor, review link, and health scoring — all working with no vendor publish integration yet.

### Step 0.1 — Project scaffold
- New Laravel 13 project with Inertia + React + Breeze/Fortify auth scaffolding.
- Configure the MySQL connection, `.env.example` with placeholders for `UPLOAD_POST_API_KEY` and `XAI_API_KEY` (unused until later phases, but reserved now).
- Set up PHPUnit.
- **Done when**: fresh install boots, migrates, and a logged-in user sees an empty dashboard shell.

### Step 0.2 — Organizations, users, roles ✅ Done
- Migrations/models: `organizations`, `users` (with `org_id`, `role`).
- Roles: Owner, Strategist, Designer, Client reviewer, Viewer (spec §3).
- Laravel policies/gates enforcing the access table in §3 (e.g. Designer restricted to assets/Figma links; Client reviewer scoped to one client with no billing visibility).
- **Done when**: PHPUnit tests cover each role's allowed/denied actions on a sample controller.
- Implemented via `App\Enums\Role`, `Organization` model, `users.org_id`/`role`/`client_id`, and
  `App\Policies\ClientPolicy` (used as the sample controller policy, enforced on `ClientController`).
  See `.junie/modules/organizations-users.md`.

### Step 0.3 — Client (CRM) model ✅ Done
- Migration/model per spec §4.2: name, legalName, website, industry, countries[], status enum, ownerUserId, startDate, retainerAmount, billingCycle, tags[], defaultLanguage, notesInternal, approvalEmail.
- CRUD controllers + Inertia pages (list, create, edit, show).
- **Done when**: an Owner can create/edit/list clients through the UI.
- Implemented via `Client` model/migration, `ClientController` (resourceful), `StoreClientRequest`/
  `UpdateClientRequest`, `CreateClientAction`/`UpdateClientAction`, and Inertia pages under
  `resources/js/pages/clients/`. See `.junie/modules/clients.md`.

### Step 0.4 — Client health computation ✅ Done
- Computed (not stored) health status: Red / Amber / Green per the rules in §4.2.
- A `healthReason` string accompanying the pill on every client row.
- Cover as a service class / accessor, not inline controller logic, so Home (Step 0.9) can reuse it.
- **Done when**: PHPUnit tests assert each of the Red/Amber/Green trigger conditions independently.
- Implemented via `App\Enums\ClientHealth`, `App\Services\ClientHealthService::compute()` (called
  through `Client::health()`), surfaced as `health`/`health_reason` in `ClientController::transform`
  and rendered as a pill on the clients index/show pages. Rules currently draw only on fields already
  on the `Client` model (status, owner, retainer/billing, start date) since BrandBrain/SocialAccount/
  Post data doesn't exist yet — see `.junie/modules/clients.md` for the exact trigger list and the note
  on revisiting once those models land. See `.junie/modules/clients.md`.

### Step 0.5 — BrandBrain ✅ Done
- 1:1 model with Client, structured fields per §4.3 (Voice, Audience, Offer, Visual, Music policy, Hashtag policy, Content pillars).
- Editor UI as tabs (ties into UI contract §9 "Brand brain editor tabs").
- `toGrokContext()` method returning markdown + JSON — implement now even though Grok isn't wired up until Phase 2, so the contract is fixed early.
- **Done when**: brand brain data round-trips through the UI and `toGrokContext()` has a passing unit test with fixture data.
- Implemented via `BrandBrain` model/migration (1:1 with `Client` via a unique FK), `App\Policies\BrandBrainPolicy`,
  `App\Actions\BrandBrains\UpdateBrandBrainAction` (upsert), `BrandBrainController` (`edit`/`update` nested under
  `clients/{client}/brand-brain`), `UpdateBrandBrainRequest`, and a tabbed Inertia editor at
  `resources/js/pages/brand-brain/edit.tsx` (new `tabs.tsx` UI component). `BrandBrain::toGrokContext()` returns
  `{markdown, json}` and is unit-tested with fixture data. The exact §4.3 field list wasn't available in this repo, so
  the json column shapes are an interim definition covering the six named categories — see
  `.junie/modules/brand-brain.md` for details and open questions.

### Step 0.6 — SocialAccount ✅ Done
- Model per §4.4: platform enum, handle, displayName, **timezone (IANA, required)**, language, country, default location, postingWindows[], personaOverride.
- `connectionStatus` enum: not_connected | connected | token_expired.
- `publisher` fields present in schema now (`provider`, `externalProfileId`, `externalAccountId`, `connectedAt`) but unused until Phase 1 — do not wire to any vendor yet.
- **Done when**: an account can be created against a client with a required, validated IANA timezone.
- Implemented via `SocialAccount` model/migration (belongs to `Client`/`Organization`), `App\Enums\Platform`
  (instagram/tiktok/facebook/linkedin, per the Phase 1 rollout order) and `App\Enums\ConnectionStatus`,
  `App\Policies\SocialAccountPolicy`, `Create`/`UpdateSocialAccountAction`, `SocialAccountController`
  (`store`/`update`/`destroy` nested under `clients`/`social-accounts`), `Store`/`UpdateSocialAccountRequest`
  (timezone validated via Laravel's `timezone:all` rule), and an inline "Social accounts" create/remove
  section on `resources/js/pages/clients/show.tsx` (mirrors the invoices section — no separate index/create/
  edit pages). Publisher fields (`provider`, `externalProfileId`, `externalAccountId`, `connectedAt`) are in
  the schema/model but unused. The exact §4.4 platform list/`postingWindows[]`/`personaOverride` shapes
  weren't available in this repo, so they're interim definitions — see `.junie/modules/social-accounts.md`
  for details and open questions.

### Step 0.7 — Campaigns and Assets ✅ Done
- Campaign model (§4.5).
- Asset model with `source` (upload | figma | url), type, dimensions, rights, `variantGroupId`.
- Upload flow to S3-compatible storage via signed URLs (spec §10 — no direct public writes).
- **Done when**: an asset can be uploaded or linked (Figma URL) and attached to a client/campaign.
- Implemented via `Campaign`/`Asset` models/migrations, `App\Enums\CampaignStatus`/`AssetSource`/
  `AssetType`, `App\Policies\CampaignPolicy`/`AssetPolicy` (Designer allowed to create/link assets
  per §3), `Create`/`UpdateCampaignAction`, `Create`/`DeleteAssetAction`, `Campaign`/
  `AssetController`, and inline "Campaigns"/"Assets" sections on `resources/js/pages/clients/show.tsx`
  (mirrors the invoices/social-accounts sections). Per explicit instruction, storage for uploads is
  **not yet wired to S3** — uploads go to Laravel's local `public` disk via a new
  `App\Contracts\AssetStorage` interface (`App\Services\LocalAssetStorage` implementation, disk name
  from `config('assets.disk')`/`ASSETS_DISK` env). The `s3` disk is already defined in
  `config/filesystems.php`; switching later should only need an env change plus a new `AssetStorage`
  implementation swapped into the `AppServiceProvider` binding — no Action/Controller changes. See
  `.junie/modules/campaigns.md` / `assets.md` for details and open questions.

### Step 0.8 — Post and PostTarget ✅ Done
- This is the core data model — implement carefully per §4.6.
- `Post`: status enum (`idea → draft → internal_review → waiting_client → changes_requested → approved → scheduled → publishing → published → failed → archived`), approvalMode, masterCaption, hashtags[], music object, location object, checklist snapshot, etc.
- `PostTarget`: one row per selected social account, with **independent** `scheduledLocalDate`/`scheduledLocalTime`/`scheduledAtUtc` per target.
- **Critical invariant**: two targets on different account timezones must keep independent local times — do not collapse to one UTC value unless the user explicitly opts into "same instant" (spec §4.6). Write this as an explicit PHPUnit test using two accounts (e.g. Europe/London and Asia/Dubai).
- **Done when**: the London/Dubai acceptance test from spec §12 passes — creating a post with two targets in different timezones shows two independent local times.
- Implemented via `Post`/`PostTarget` models/migrations, `App\Enums\PostStatus`/`ApprovalMode`,
  `App\Policies\PostPolicy` (mirrors `CampaignPolicy`), `App\Actions\Posts\CreatePostAction`
  (computes each target's `scheduled_at_utc` independently from its own `SocialAccount::timezone`),
  `PostController::store` (`clients/{client}/posts`), and `StorePostRequest`. The critical
  London/Dubai invariant is covered by
  `tests/Feature/PostControllerTest::test_targets_on_different_timezone_accounts_keep_independent_local_times`.
  Status transitions, `ReviewToken`, activity log, and comments are now implemented (Step 0.9); the
  editor UI (Step 0.10) is not implemented yet. The exact §4.6 field list
  (in particular `approvalMode` semantics) wasn't available in this repo, so it's an interim
  two-value enum — see `.junie/modules/posts.md` for details and open questions.

### Step 0.9 — Post status transitions ✅ Done
- `POST /posts/:id/transition { to: status }` with guardrails (no illegal transitions — enumerate the state machine explicitly as a first-class map, not scattered if/else).
- Approval records, Activity log, Comments (with `internalOnly` flag).
- `ReviewToken` model: token, postId or clientId, expiresAt, revoked.
- **Done when**: PHPUnit tests cover both valid and rejected transitions.
- Implemented via `App\Actions\Posts\PostStatusTransitionMap` (the explicit state-machine map),
  `App\Actions\Posts\TransitionPostStatusAction` (records `PostActivityLog` always, `PostApproval` on
  `approved`/`changes_requested`), `PostPolicy::transition`/`comment`, `TransitionPostRequest`/
  `StorePostCommentRequest`, `PostController::transition`/`comment` (`POST /posts/{post}/transition`
  and `/posts/{post}/comments`), and the new `PostActivityLog`/`PostApproval`/`PostComment`/
  `ReviewToken` models/migrations plus `CreatePostCommentAction`/`CreateReviewTokenAction`. A
  `Role::ClientReviewer` may only move a `waiting_client` post of their own client to
  `approved`/`changes_requested` (the future review-portal actions) and can never write an
  `internal_only` comment. `ReviewToken` is hashed at rest (§10) but has no HTTP routes yet — the
  actual `/review/:token` portal is Step 0.13. No editor UI for any of this yet (Step 0.10). See
  `.junie/modules/posts.md` for the full transition map and open questions (notably: the map doesn't
  yet branch on `approval_mode`).

### Step 0.10 — Editor UI ✅ Done
- Per §9: choose client first → accounts filtered to that client → one schedule row per target → character counts → location → music (with `apiAttachable` badge, publish adapter stubbed as unavailable in this phase) → checklist panel.
- Checklist logic per §6, run client-side and re-validated server-side before any transition to `scheduled`.
- **Done when**: a post can move from draft through to "approved" via the UI with the checklist enforced.
- Implemented via `App\Actions\Posts\EvaluatePostChecklistAction` (interim §6 checklist: caption,
  targets, per-target schedule, media — media is skipped only when every target platform is
  text-only-capable via new `Platform::isTextOnlyCapable()`, implementing the Phase 0 exit criterion
  "Schedule blocked without media unless platform is text-only"), `UpdatePostAction`/
  `UpdatePostRequest`, `PostController::edit`/`update` (`posts.edit`/`posts.update`), the new
  `post_assets` pivot for media attachment, and `resources/js/pages/posts/edit.tsx` — a full-page
  editor (client already fixed via the client detail page) with per-target independently-scheduled
  rows and character counts against `Platform::captionLimit()`, a music field badged "Not available
  yet" (publish adapter stubbed until Phase 1), a location field, an asset picker, the checklist
  panel, transition buttons (disabled client-side for `scheduled` when the checklist hasn't passed),
  comments, and the activity log. `TransitionPostRequest` re-validates the checklist server-side
  before any `scheduled` transition, independent of the client-side display. The exact §6/§9 field
  shapes weren't available in this repo — see `.junie/modules/posts.md` for the interim definitions
  and open questions.

### Step 0.11 — Calendar UI ✅ Done
- Month / week / day / list views.
- Toggle between "each account local" and "my timezone" display modes.
- Filters (client, campaign, status, platform).
- **Done when**: the same post's two targets (different timezones) render at their correct local times, and the toggle correctly re-renders in the org's default timezone.
- Implemented via `App\Policies\PostPolicy::viewCalendar` (Owner/Strategist/Designer/Viewer, plus
  `Role::ClientReviewer` scoped to their own client), `App\Http\Controllers\CalendarController::index`
  (route `calendar`, `GET /calendar`) which flattens every `Post`'s `PostTarget`s into "occurrences"
  with both an `account_local` (from the target's own social account timezone, same as Step 0.10)
  and `org_timezone` (derived from `scheduled_at_utc` and `Organization::default_timezone`) rendering
  pre-computed server-side, plus server-side `client_id`/`campaign_id`/`status`/`platform` filters.
  `resources/js/pages/calendar/index.tsx` (new sidebar "Calendar" entry) renders Month/Week/Day/List
  views and the local/org-timezone toggle purely client-side over that single payload — no new
  domain model, this is a read-only projection over the existing Posts module. See
  `.junie/modules/calendar.md` for details and open questions.

### Step 0.12 — Home (needs-attention) ✅ Done
- `GET /home` payload: failed publishes (unused until Phase 1, but structure it), waiting approvals, missing media, disconnected accounts.
- UI rows open the relevant post.
- **Done when**: seeded fixtures for each health/attention trigger produce a Home row.
- Implemented via `App\Actions\Home\GetNeedsAttentionItemsAction` (reuses
  `EvaluatePostChecklistAction`'s `media` item so the missing-media rule never drifts from the
  editor's own checklist), `App\Policies\PostPolicy::viewHome` (Owner/Strategist/Designer/Viewer
  only — a `Role::ClientReviewer` gets a 403, since this is an internal ops view), and
  `App\Http\Controllers\HomeController::index` (route name `needs-attention`, since `home` was
  already taken by the marketing page at `/`, `GET /home`). `resources/js/pages/home.tsx` (new
  sidebar "Needs attention" entry) renders four cards — failed publishes, waiting approvals,
  missing media, disconnected accounts — each row linking to `posts.edit` (or `clients.show` for
  account rows, which aren't tied to a single post). See `.junie/modules/home.md` for the exact
  status/trigger definitions and open questions.

### Step 0.13 — Client review portal (`/review/:token`) ✅ Done
- Light theme, no money fields, Approve / Request changes actions only.
- Token hashed at rest (§10); queries scoped strictly by `token.clientId`.
- **Done when**: the acceptance test from §12 passes — a reviewer token cannot see retainer amount or any other client's data.
- Implemented via `App\Http\Controllers\ReviewController` (`show`/`decide`, routes `review.show`/
  `review.decide` at `/review/{token}`, deliberately outside the `auth`/`verified` middleware group)
  which resolves/validates the `ReviewToken` itself (hash lookup + `isValid()` + client/post
  scoping, 404 on any mismatch) rather than a policy, and `resources/js/pages/review/show.tsx` (a
  light-themed, no-sidebar page per brand guidelines §7 — no money fields, just the caption/
  hashtags/media/targets and Approve/Request changes). The client is notified by email the moment a
  post lands in `waiting_client`: `TransitionPostStatusAction` (now accepting a nullable `$user`, to
  support the review portal's unauthenticated decisions) calls the new
  `App\Actions\Posts\SendClientReviewRequestedEmailAction`, which issues a `ReviewToken` via
  `CreateReviewTokenAction` and sends `App\Mail\ClientReviewRequestedMail` (a Resend-delivered,
  branded HTML email at `resources/views/emails/client-review-requested.blade.php`) to
  `Client::approval_email` — skipped with a warning log (not a hard failure) if that field is unset.
  See `.junie/modules/posts.md` for details and open questions.

### Step 0.14 — Preview mode ✅ Done
- `/preview/*` — frozen fixture/seed data, visible banner, **no writes to production data**.
- All publish-adjacent routes return 403 in preview context (relevant once Phase 1 lands, but the guard should exist now).
- **Done when**: the §12 acceptance test passes — preview cannot trigger a write, confirmed by attempting a mutating request against a preview-scoped resource and asserting rejection.
- Implemented via `Database\Seeders\PreviewSeeder` (idempotent fixture: a dedicated "Preview Studio"
  org/owner/client/campaign/social account/post+target), `App\Http\Middleware\EnsurePreviewMode`
  (aliased `preview`) which 403s any non-GET/HEAD request before any controller runs and otherwise
  signs the visitor in as the fixture owner, and a `/preview/*` route group in `routes/web.php` that
  mirrors the authenticated app routes one-for-one (so future publish-adjacent routes inherit the
  same guard automatically). The `preview` shared Inertia prop (matched by URL prefix, see
  `HandleInertiaRequests`) drives a persistent banner (`resources/js/components/preview-banner.tsx`)
  on every preview page. See `.junie/modules/preview-mode.md` for details and a known gap around
  forms in reused pages still pointing at non-`preview.` routes.

### Step 0.15 — Settings ✅ Done
- Owner-only: Upload-Post key field (stored server-side only), xAI key field, default org timezone.
- Confirm no secret ever appears in an Inertia page prop or client-visible payload — add an automated check (e.g. a PHPUnit test asserting the props payload excludes key fields) rather than relying on manual review.
- **Done when**: keys save server-side and are never present in a rendered page's JSON props.
- Implemented via nullable `organizations.upload_post_key`/`xai_key` columns
  (`encrypted` cast + `#[Hidden]` on `Organization`, so they're encrypted at rest and never serialize
  into any Inertia prop), `App\Policies\OrganizationPolicy` (Owner-only, scoped to the user's own org),
  `App\Actions\Organizations\UpdateOrganizationSettingsAction` (blank keys leave the stored key
  untouched), `OrganizationSettingsUpdateRequest`, `Settings\OrganizationController`
  (`organization.edit`/`organization.update` in `routes/settings.php`), and a new "Organization" entry
  in the settings sidebar (`resources/js/layouts/settings/layout.tsx`, shown only to Owners) rendering
  `resources/js/pages/settings/organization.tsx`. See `.junie/modules/settings.md` for details.

### Phase 0 exit criteria (from spec §12)
- Dual-timezone editor test passes.
- Reviewer isolation test passes.
- Preview cannot write/publish.
- Schedule blocked without media unless platform is text-only.

---

## Phase 1 — Publish (Upload-Post)

Goal: real scheduled posts actually publish, via Upload-Post, starting with one test brand then one paying client.

### Step 1.1 — PublishAdapter interface ✅ Done
- Define the adapter contract in PHP (interface), mirroring spec §7:
  - `connectAccount(account)`
  - `handleCallback(query)`
  - `publish(post, targets)`
  - `cancel(externalPostId)` (optional)
  - `health(account)`
- **Done when**: the interface exists with no implementation yet, and a null/no-op adapter satisfies it for tests.
- Implemented via `App\Contracts\PublishAdapter` (methods above, `publish()` taking a
  `Collection<SocialAccount>` of targets and returning a `Collection<App\Support\Publishing\TargetResult>`
  per spec §7's `TargetResult` shape), `App\Services\Publishing\NullPublishAdapter` (no-op: never
  connects/publishes/healthy, used as the default binding until Step 1.2), bound in
  `AppServiceProvider::register()`. No vendor call/UI yet — this is contract-only, per plan. See
  `.junie/modules/publishing.md`.

### Step 1.2 — Upload-Post adapter implementation ✅ Done
- Implement the interface against Upload-Post's API per §7.1 field mapping:
  - `scheduledDate` from `scheduledAtUtc`.
  - Caption, first comment, media URL.
  - Instagram: `location_id`, `media_type` (REELS/IMAGE/STORIES), `audio_name` fallback only when no real audio id.
  - TikTok: `tiktokMusicId`, location, `tiktokIsAiGenerated` from the post flag.
  - LinkedIn/Facebook: caption + media + schedule only.
- Return `TargetResult { accountId, ok, externalPostId, error, sentFields[], skippedFields[] }`.
- **Done when**: a mocked Upload-Post client (HTTP fake) round-trips a publish call for each platform's field mapping.
- Implemented via `App\Services\Publishing\UploadPostAdapter` (`publish()` posts to
  `POST {base_url}/uploadposts/schedule` via `Http`, authenticated per-org with
  `SocialAccount->organization->upload_post_key`), now bound as the default `PublishAdapter` in
  `AppServiceProvider::register()` (replacing `NullPublishAdapter`), plus
  `config('services.upload_post.base_url')`. Common fields map `user`/`title`/`platform`/
  `scheduled_date`/`media_urls`/`hashtags`; Instagram adds `location_id`/`media_type`/`audio_id`
  (falling back to `audio_name`); TikTok adds `tiktok_music_id`/`location` (`tiktok_is_ai_generated`
  always skipped — no such `Post` flag exists); LinkedIn/Facebook send only the common fields. No
  `first comment` field exists on `Post` yet, so it isn't sent. `connectAccount`/`handleCallback`/
  `cancel` remain interim stubs for Steps 1.3/1.5. Tested via
  `tests/Unit/Services/Publishing/UploadPostAdapterTest.php` using `Http::fake()`. See
  `.junie/modules/publishing.md` for the exact field list and open questions (Upload-Post's real
  request/response shape wasn't available in this repo).

### Step 1.3 — Account connect flow ✅ Done
- `POST /accounts/:id/connect` → Upload-Post OAuth/connect URL.
- `handleCallback` persists `externalProfileId`, sets `connectionStatus = connected`.
- **Done when**: connect flow works end-to-end against Upload-Post sandbox/test credentials.
- Implemented via `App\Services\Publishing\UploadPostAdapter::connectAccount()` (calls Upload-Post's
  `POST /uploadposts/users/generate-jwt` with a `redirect_url` back to the app, returning the vendor's
  `access_url`) and `handleCallback()` (persists `external_profile_id`/`connection_status =
  connected`/`connected_at` on the `SocialAccount` identified by the `social_account_id` carried in
  the callback query string). Wired up via `App\Actions\SocialAccounts\ConnectSocialAccountAction`,
  `SocialAccountController::connect` (`POST /social-accounts/{socialAccount}/connect`, authed,
  redirects to the vendor URL via `Inertia::location()`) and `::callback`
  (`GET /social-accounts/callback`, public — mirrors the `review/{token}` portal), plus a "Connect"
  button on the client show page's "Social accounts" tab. No real Upload-Post sandbox/test
  credentials were available in this repo, so the end-to-end vendor round trip is covered via
  `Http::fake()` in `tests/Unit/Services/Publishing/UploadPostAdapterTest.php` and
  `tests/Feature/SocialAccountControllerTest.php` rather than a live sandbox call — see
  `.junie/modules/publishing.md` for the open question on the callback's lack of signature
  verification. See `.junie/modules/publishing.md` and `.junie/modules/social-accounts.md`.

### Step 1.4 — Schedule endpoint ✅ Done
- `POST /posts/:id/schedule` — re-run the full checklist (§6) server-side, then enqueue a publish job. Nothing calls the vendor until the checklist passes.
- Publish job dispatched via Laravel queue, calling the adapter's `publish()`.
- On success: target/post status → `scheduled` (pre-publish-time) or `published`; store external ids per target.
- On failure: post/target → `failed`; surfaced on Home; raw vendor error stored on the target.
- **Done when**: the §12 acceptance test passes — scheduling creates a vendor job at the correct UTC instant.
- Implemented by reusing the existing checklist-gated `scheduled` transition (Step 0.10's
  `TransitionPostRequest`/`posts.transition` endpoint) rather than adding a separate route — see
  `.junie/modules/publishing.md`'s Known Gaps for the rationale. `App\Actions\Posts\TransitionPostStatusAction`
  now dispatches the new `App\Jobs\PublishPostJob` whenever `$to === PostStatus::Scheduled`, delayed
  until the earliest target's `scheduled_at_utc` (immediate if already due). The job re-checks the
  post is still `scheduled`, transitions it to `publishing`, calls `PublishAdapter::publish()` for
  every target's `SocialAccount`, persists each `TargetResult` onto its `PostTarget` (new
  `status`/`external_post_id`/`error` columns, migration
  `2026_09_19_150000_add_publish_result_columns_to_post_targets_table`), then transitions the post to
  `published` (all targets ok) or `failed` (any target failed) — `failed` posts already surface on
  Home via the existing `GetNeedsAttentionItemsAction`. Covered by
  `tests/Unit/Jobs/PublishPostJobTest.php` and a new assertion in `PostTransitionControllerTest`.
  See `.junie/modules/publishing.md` and `.junie/modules/posts.md`.

### Step 1.5 — Webhook / poll sync ✅ Done
- `POST /webhooks/upload-post` with signature verification.
- Fallback poll job if webhooks are unreliable in testing.
- Syncs published/failed status back onto posts/targets.
- **Done when**: a simulated webhook payload correctly transitions a `publishing` post to `published` or `failed`.
- Implemented via `App\Contracts\PublishAdapter::checkStatus()` (new contract method; `Null`/
  `UploadPostAdapter` implementations), `App\Actions\Publishing\SyncPublishStatusAction` (the
  shared seam that finalizes a `pending` `PostTarget` and transitions its post once every target
  has resolved), `App\Http\Controllers\UploadPostWebhookController` (`POST /webhooks/upload-post`,
  public, excluded from CSRF in `bootstrap/app.php`), and
  `App\Actions\Publishing\PollPendingPublishStatusesAction` +
  `PollPendingPublishStatusesCommand` (`publishing:poll-pending-statuses`, scheduled every 5
  minutes) as the fallback poll. Confirmed against Upload-Post's real docs
  (https://docs.upload-post.com/api/webhooks, https://docs.upload-post.com/api/upload-status):
  the webhook verifies `X-Upload-Post-Signature: sha256=<hex>` = `HMAC_SHA256(webhook_secret,
  "{timestamp}.{raw body}")` (with replay protection via `X-Upload-Post-Timestamp`), where
  `webhook_secret` is a new per-org `organizations.upload_post_webhook_secret` column (Upload-Post
  issues one secret per account, not a global one) alongside `upload_post_key` on the Organization
  settings page; the `upload_completed` payload is matched to a `PostTarget` by `job_id` *and*
  `platform` (one job fans out to one event per platform); and `checkStatus()` polls the real
  `GET /uploadposts/status?request_id=&job_id=` endpoint. `PublishPostJob`'s existing synchronous
  published/failed marking from Step 1.4 was deliberately left unchanged; the webhook/poll finalize
  any target still `pending` on a `publishing` post instead — see
  `.junie/modules/publishing.md`'s Known Gaps for the rationale. Tested via
  `SyncPublishStatusActionTest`, `PollPendingPublishStatusesActionTest`,
  `UploadPostWebhookControllerTest`, and new `checkStatus()` coverage in
  `NullPublishAdapterTest`/`UploadPostAdapterTest` (the webhook test directly matches the §12-style
  acceptance scenario: a simulated payload flips a `publishing` post to `published`/`failed`). See
  `.junie/modules/publishing.md`.

### Step 1.6 — Token expiry / health integration ✅ Done
- `health(account)` check feeding `connectionStatus`.
- Confirms the §12 acceptance test: a disconnected token flips the account to `token_expired`, client health to Red, and produces a Home row.
- **Done when**: that test passes end-to-end.
- Implemented via `App\Actions\SocialAccounts\CheckSocialAccountHealthAction` (calls the already-
  existing `PublishAdapter::health()` for a single `connected` account and flips it to
  `token_expired` on failure — `not_connected`/already-`token_expired` accounts are left alone,
  since only the connect flow (Step 1.3) clears `token_expired`) and
  `App\Actions\SocialAccounts\CheckAllSocialAccountsHealthAction` (sweeps every `connected`
  account), run via the new `social-accounts:check-health` command scheduled hourly in
  `routes/console.php` (mirrors Step 1.5's `publishing:poll-pending-statuses` pattern).
  `ClientHealthService` gained a new red trigger — any client with a `token_expired`
  `SocialAccount` is Red — which the Home "needs attention" `disconnectedAccounts` list already
  surfaced (Step 0.12) without changes. `ClientController::index`/`GetClientHealthSummaryAction`
  now eager-load `socialAccounts` to avoid N+1 queries from the new health check. Covered by
  `CheckSocialAccountHealthActionTest`, `CheckAllSocialAccountsHealthActionTest`, new
  `ClientHealthServiceTest` cases, and the full §12 acceptance scenario in
  `tests/Feature/SocialAccountHealthAcceptanceTest.php`. See `.junie/modules/social-accounts.md`,
  `.junie/modules/publishing.md`, and `.junie/modules/clients.md`.

### Step 1.7 — Platform rollout order ✅ Done
- Enable in this order, each gated behind its own flag until confirmed working: Instagram feed → Reels → TikTok → Facebook → LinkedIn.
- **Done when**: each platform has a passing publish test against sandbox data before the next is enabled.
- Implemented via `App\Enums\PlatformRolloutStage` (the five milestones — Instagram feed/Reels
  aren't separate `Platform` enum values, so this enum exists specifically to give each its own
  togglable stage), `config('publishing.rollout')` (one boolean flag per stage, each overridable
  via its own env var), and `App\Services\Publishing\PlatformRolloutGate` (`stageFor()` resolves
  a `SocialAccount`/`Post` pair to its stage — Instagram feed vs. Reels from the post's first
  attached asset type, same inference `UploadPostAdapter::applyInstagramFields()` already uses;
  `stageEnabled()`/`isEnabled()` read the config flag). `UploadPostAdapter::publishToTarget()`
  now consults the gate before any vendor call — a disabled stage returns a failed `TargetResult`
  ("Publishing to {stage} is not yet enabled") without ever reaching Upload-Post. All five stages
  default to enabled, since each already has a passing publish test against faked sandbox-shaped
  data from Steps 1.2-1.6 (`UploadPostAdapterTest`) — the flags exist so a future platform/adapter
  regression can be rolled back independently per stage without touching the others. Covered by
  `PlatformRolloutGateTest` (stage resolution + config-driven enable/disable) and two new
  `UploadPostAdapterTest` cases (disabled stage never calls the vendor; enabled stage does). See
  `.junie/modules/publishing.md`.

### Step 1.8 — Music/sound API integration (Instagram + TikTok) ✅ Done
- `Post::music` is already read by `UploadPostAdapter` (`audio_id`/`audio_name` for Instagram,
  `tiktok_music_id` for TikTok, per Step 1.2), but today it's only ever populated by hand — there's
  no way to search or browse a real sound/track catalog. This step adds that lookup so editors can
  pick a real track and have its vendor-specific id land in `Post::music` unchanged, ready for the
  existing adapter mapping to carry it through to Upload-Post.
- Break down into:
  - **1.8.1 — `MusicProvider` contract** ✅ Done: `App\Contracts\MusicProvider` with
    `search(string $query, Platform $platform): Collection<MusicTrack>` and
    `find(string $id, Platform $platform): ?MusicTrack`, mirroring the `PublishAdapter` pattern
    (interface first, bound in a Service Provider) so the vendor is swappable/mockable in tests.
    `App\Support\Publishing\MusicTrack`: readonly DTO (`id`, `name`, `artist`, `previewUrl`,
    `platform`). No default binding registered yet — that lands with 1.8.2's
    `UploadPostMusicProvider`, the first (and currently only) concrete implementation. Covered by
    `tests/Unit/Contracts/MusicProviderTest.php` and `tests/Unit/Support/Publishing/MusicTrackTest.php`.
    See `.junie/modules/publishing.md`.
  - **1.8.2 — Upload-Post sound-library adapter** ✅ Done: `App\Services\Publishing\UploadPostMusicProvider`
    implements `MusicProvider` against Upload-Post's real music-search endpoint, bound as the
    default `MusicProvider` in `AppServiceProvider`. Confirmed against Upload-Post's live API
    reference that only TikTok has a real vendor endpoint (`GET /uploadposts/tiktok/music/search`,
    scoped to a connected TikTok profile) — Upload-Post has no Instagram audio-search endpoint at
    all, so Instagram always returns an empty/`null` result without a vendor call. Since the
    contract carries no org/account parameter, the org is resolved from the authenticated user
    (mirroring how every controller resolves it) and the vendor's required `profile` param from
    that org's first connected TikTok `SocialAccount`; `find()` re-uses the search endpoint and
    matches the vendor's `id` locally (no get-by-id endpoint exists). Logs every request/failure
    the same way `UploadPostAdapter` does; no raw API key in logs. Covered by
    `tests/Unit/Services/Publishing/UploadPostMusicProviderTest.php`. See
    `.junie/modules/publishing.md`.
  - **1.8.3 — `SearchMusicAction`** ✅ Done: `App\Actions\Publishing\SearchMusicAction` — a thin Action
    constructor-injected with `App\Contracts\MusicProvider` (never the concrete
    `UploadPostMusicProvider`), invoked as `(string $query, Platform $platform)` and delegating
    entirely to `MusicProvider::search()`. A single read call against the provider, so no
    `DB::transaction()` is needed; logs the search start and result count (platform + count only,
    no query text) the same way other publishing Actions/Services do. No controller/route wired up
    yet — that lands with 1.8.4's editor music picker, which is the first caller. Covered by
    `tests/Unit/Actions/Publishing/SearchMusicActionTest.php` (delegates to a mocked
    `MusicProvider`, returns an empty collection when nothing matches). See
    `.junie/modules/publishing.md`.
  - **1.8.4 — Editor music picker (UI)** ✅ Done: `PostController::musicSearch` (`GET
    posts/{post}/music-search`, `view` authorization) validates `query`/`platform`, short-circuits
    to an empty result for any platform other than Instagram/TikTok (matching Step 1.8.2's real
    vendor coverage), and otherwise delegates to `SearchMusicAction`, returning each `MusicTrack` as
    `{ id, name, artist, preview_url }` JSON. The `posts/edit` editor's Music field (previously a
    "Not available yet" locked text input) is now a live search box shown only while at least one
    selected target account is Instagram/TikTok — with a platform toggle when both are selected —
    that calls the endpoint via `fetch()` (mirroring the `clients/show` connect-flow pattern), lists
    matches, and on selection writes the track's `{ id, name }` into hidden `music[id]`/`music[name]`
    inputs, unchanged from the shape `UploadPostAdapter` already expects. No target account on
    Instagram/TikTok keeps the field collapsed to a hint instead of a picker. Covered by two new
    `PostEditorControllerTest` cases (returns the mocked `MusicProvider`'s tracks for TikTok;
    short-circuits without calling the provider for a platform like Facebook). See
    `.junie/modules/publishing.md`.
  - **1.8.5 — End-to-end verification** ✅ Done: confirmed a real music selection flows unchanged
    through to the existing `audio_id`/`tiktok_music_id` mapping in
    `UploadPostAdapter::publish()` — no adapter changes were required, since `applyInstagramFields()`/
    `applyTikTokFields()` already read `Post::music['id']` verbatim (Step 1.2). Added
    `tests/Feature/MusicSelectionAcceptanceTest.php`: two §12-style acceptance scenarios that call
    `PostController::musicSearch` against a mocked `MusicProvider` track, save the returned `id`/`name`
    onto the post via `PostController::update` exactly as the editor UI does (Step 1.8.4), then run
    the real `UploadPostAdapter::publish()` against a faked Upload-Post response and assert the
    selected track's id lands on `audio_id` (Instagram) / `tiktok_music_id` (TikTok) in the request
    actually sent to the vendor.
- **Done when**: searching returns real Upload-Post sound results for both Instagram and TikTok, a
  selected track's id round-trips into `Post::music`, and a §12-style acceptance test confirms it
  reaches `audio_id`/`tiktok_music_id` in the vendor payload built by `UploadPostAdapter`. ✅ See
  `tests/Feature/MusicSelectionAcceptanceTest.php` and `.junie/modules/publishing.md`.

### Step 1.9 — Real Instagram audio search (Meta Graph API direct integration) ✅ Done
- Root cause of the empty-array Instagram music search reported against Step 1.8: Upload-Post
  (the only vendor `UploadPostMusicProvider` talks to) has **no Instagram audio-search endpoint at
  all** — confirmed again against Upload-Post's live API reference. Meta *does* expose a real
  search API for this
  (https://developers.facebook.com/documentation/instagram-platform/content-publishing/audio-api —
  `GET /ig_audio?audio_type={original_sound|music}&user_id={ig-user-id}&search_query={query}&access_token={token}`),
  but it is a **separate Graph API surface this app has no credentials for today**: it requires a
  Facebook Login (not Instagram Login) User access token scoped with `instagram_basic` +
  `instagram_content_publish`, plus the target Instagram Business user id — neither of which exist
  anywhere in the codebase (`SocialAccount`/`Organization` have no access-token column, and there
  is no Facebook App configured in `.env`/`config/services.php`). This step adds that missing
  credential/connect layer and a second, direct-to-Meta `MusicProvider` implementation for
  Instagram, leaving TikTok's existing Upload-Post-backed path (Step 1.8.2) untouched.
- Break down into:
  - **1.9.1 — Facebook App + config scaffolding** ✅ Done: register a Facebook App (Facebook Login for
    Business product) with `instagram_basic` + `instagram_content_publish` permissions submitted
    for review; add `services.facebook.{client_id,client_secret,redirect_uri}` to
    `config/services.php`, backed by new `FACEBOOK_CLIENT_ID`/`FACEBOOK_CLIENT_SECRET`/
    `FACEBOOK_REDIRECT_URI` env vars (never logged, mirroring the `upload_post_key` handling
    convention). **Done when**: config resolves in a `config:show services.facebook`-style test and
    the app boots with the new env vars unset (falls back to `null`, no crash).
  - **1.9.2 — Migration: store a Meta user access token per `SocialAccount`** ✅ Done: add
    `meta_access_token` (encrypted cast), `meta_access_token_expires_at`, and
    `meta_instagram_user_id` nullable columns to `social_accounts` (only ever populated for
    Instagram accounts). Add the three fields to the model's `casts()`/PHPDoc/`Fillable` list,
    following the existing `external_account_id` pattern. **Done when**: a migration + factory
    state exist and a model test confirms the token round-trips through Laravel's `encrypted`
    cast (never stored/logged in plaintext).
  - **1.9.3 — Facebook Login connect flow for Instagram accounts** ✅ Done: new
    `App\Http\Controllers\Auth\FacebookInstagramConnectController` with `redirect()`/`callback()`
    actions (`GET clients/{client}/social-accounts/{socialAccount}/facebook/connect` and
    `/facebook/callback`), using Laravel Socialite's Facebook driver (or a thin
    `Http`-based OAuth exchange if Socialite is undesired) to obtain a long-lived User access
    token and the connected Instagram Business user id, then persist them via a new
    `App\Actions\SocialAccounts\ConnectMetaAudioAccessAction` (wrapped in `DB::transaction()`,
    logs success/failure with the social account id, never the token). This is **additive** —
    it does not replace or change how Instagram accounts are connected for publishing via
    Upload-Post; it only adds the extra Graph API grant needed for audio search. **Done when**: a
    feature test drives the callback with a faked token exchange and asserts the three columns
    from 1.9.2 are populated on the right `SocialAccount`.
  - **1.9.4 — `MetaGraphMusicProvider`** ✅ Done: new `App\Services\Publishing\MetaGraphMusicProvider`
    implementing `App\Contracts\MusicProvider` (Step 1.8.1), calling `GET /{version}/ig_audio`
    directly against `graph.facebook.com` for `Platform::Instagram` only (delegates every other
    platform to the existing `UploadPostMusicProvider` via composition, so TikTok behavior from
    Step 1.8.2 is unchanged). Resolves the connected Instagram `SocialAccount`'s
    `meta_access_token`/`meta_instagram_user_id` from 1.9.2 (via the authenticated user's
    organization, same resolution pattern as `UploadPostMusicProvider::connectedTikTokAccount()`);
    throws `MusicProviderUnavailableException` with an actionable message
    ("Connect Instagram via Facebook Login before searching for music.") when those fields are
    empty, instead of silently returning an empty collection. Maps `audio_id` → `MusicTrack::id`,
    `title` → `name`, `display_artist` → `artist`, `download_url`/`on_platform_audio_preview_link`
    → `previewUrl`. Logs every request/failure the same way `UploadPostMusicProvider` does; the
    access token is never logged. `find()` uses `GET /{version}/{ig-audio-id}` (Get Audio
    Metadata). **Done when**: `AppServiceProvider`'s `MusicProvider` binding is swapped to this
    combinator class and `tests/Unit/Services/Publishing/MetaGraphMusicProviderTest.php` covers:
    real search results for Instagram (faked Graph HTTP response), TikTok still delegating to
    `UploadPostMusicProvider` unchanged, and the "not connected" error path.
  - **1.9.5 — Editor UI: connect prompt** ✅ Done: extend the `posts/edit` Music field (Step 1.8.4) so
    that when Instagram search throws the "not connected" error from 1.9.4, the picker shows an
    inline "Connect Instagram for music search" link to the 1.9.3 connect route for that account,
    instead of a bare empty list. **Done when**: a `PostEditorControllerTest` case asserts the
    controller surfaces a distinguishable `error_code` (e.g. `meta_not_connected`) the frontend can
    key off, and a component-level check confirms the link renders.
  - **1.9.6 — End-to-end verification** ✅ Done: repeat the Step 1.8.5 acceptance pattern for Instagram
    specifically — search via `MetaGraphMusicProvider` against a faked Graph response, save the
    returned track onto `Post::music`, then run `UploadPostAdapter::publish()` and assert the same
    `audio_id` lands in the Upload-Post payload sent for the Instagram target, proving the new
    search path still feeds the unchanged publish path from Step 1.2.
- **Done when**: searching for a song name while targeting Instagram in the editor returns real
  results from Meta's `ig_audio` endpoint (not an empty array) once an account has completed the
  1.9.3 connect flow, a clear "connect Instagram" prompt is shown when it hasn't, TikTok search
  behavior is unchanged, and a §12-style acceptance test confirms a selected Instagram track's
  `audio_id` still reaches the vendor payload built by `UploadPostAdapter`. ✅ See
  `tests/Feature/MetaAudioSelectionAcceptanceTest.php`, `.junie/modules/publishing.md`, and
  `.junie/modules/social-accounts.md`.

### Phase 1 exit criteria
- One test brand fully publishing on LinkedIn/Facebook/IG feed.
- One real paying client live on the same set.
- Token-expiry and failure-surfacing acceptance tests passing.

---

## Phase 2 — DeepSeek Trends & Recommendations

Goal: AI-assisted trend discovery and suggestions for posts, captions, hashtags, alt text, and posting
times — powered by DeepSeek using client context and the brand brain. No auto-posting; humans remain
in control. This is a flagship, market-differentiating phase — beyond "generate a caption", it should
make Graphy feel like it has a strategist embedded in the product: proactive, on-brand, measurable,
and trustworthy. Steps 2.1-2.6 below are implemented (see `.junie/modules/ai.md` for the authoritative
as-built record); Steps 2.7 onward (including the standout features called out in Step 2.12) are the
remaining/expanded work for this pass.

### Step 2.1 — AI provider contract ✅ Done
- Introduce `App\Contracts\AiProvider` with methods shaped for our use cases (final signatures may adjust during implementation):
  - `suggestCaptions(Post $post): AiCaptionSuggestions`
  - `recommendPosts(Client $client): AiPostIdeas`
  - `discoverTrends(Client $client, array $platforms): AiTrends`
- Add lightweight DTOs under `App\Support\AI\` for strong typing: `AiCaptionSuggestion`, `AiCaptionSuggestions`, `AiPostIdea`, `AiPostIdeas`, `AiTrend`, `AiTrends` (readonly properties; JSON-serializable for API/UI).
- Bind via a Service Provider so the implementation is swappable/mockable in tests.
- Tests: unit test the interface contracts and DTO shape construction with simple factories.

### Step 2.2 — DeepSeek implementation ✅ Done
- Implement `App\Services\AI\DeepSeekProvider` against DeepSeek's API.
- Configuration: `config('ai.deepseek')` with `api_key` (env `DEEPSEEK_API_KEY`), `model` (env `DEEPSEEK_MODEL`, default a stable general model), `base_url`, `timeout`, `max_tokens`.
- Prompting:
  - Build a system prompt from brand context: brand voice, pillars, do/don'ts (`weNeverSay[]`), audience, and platform constraints (character limits, line breaks, link policy, emoji usage).
  - Include client industry, current campaigns, and any provided asset metadata (e.g., music selection, media type) as user/context messages.
  - For trends, request use of DeepSeek's browsing/search capability if available; otherwise instruct the model to propose trend hypotheses with clear confidence markers.
- Enforce structured JSON outputs with strict schemas for each method. Reject and retry up to 1 time if schema invalid; log and return a safe failure otherwise.
- Logging: `info` on request start/end (org/client/post ids, method, model), `warning` on retries, `error` on failures; never log secrets or raw prompts.
- Rate limiting/backoff: trivial exponential backoff on HTTP 429/5xx with jitter.
- Tests: unit tests with HTTP fakes verifying schema enforcement, retries, logging, and that secrets aren’t logged.

### Step 2.3 — Actions wrapping AI calls ✅ Done
- `App\Actions\AI\DiscoverTrendsAction` — inputs: client, platforms[]; returns `AiTrends`. Caches per client/platform set for a short TTL (e.g., 2h) to avoid spam.
- `App\Actions\AI\RecommendPostsAction` — inputs: client; returns `AiPostIdeas`. Optionally persists selected ideas as draft `Post` rows inside a `DB::transaction()` when explicitly requested by a user action.
- `App\Actions\AI\SuggestCaptionsForPostAction` — inputs: post; returns `AiCaptionSuggestions`. May write chosen suggestion into the post when confirmed by user. Never auto-schedules.
- Controller methods remain thin and delegate to these Actions. All Actions accept the `AiProvider` interface (constructor-injected), not the DeepSeek concrete class.
- Tests: unit tests for each Action (mocks `AiProvider`) + a feature test per Action path.

### Step 2.4 — Editor UI: "Suggest with AI" ✅ Done
- In the post editor (§9), add an AI panel:
  - "Suggest captions" button populates a side list; selecting one fills the caption field and alt text; hashtags as a separate selectable list; first comment optional output.
  - Show token/spinner states; handle retries gracefully; never overwrite user text without confirmation.
- On the client dashboard, add a "Trends & Ideas" tab/panel showing recent `AiTrend` items and `AiPostIdea`s for selected platforms with filters by pillar/campaign.
- Access control: Owner/Strategist only by default; gated by `config('features.ai')` flag and per-org enable switch.
- Tests: feature tests asserting endpoints are authz-protected and UI receives structured props without secrets.

### Step 2.5 — Scheduled daily brief ✅ Done
- Nightly job per client calls `DiscoverTrendsAction` and stores a "Daily Brief" note (internal-only) summarizing top trends and 3–5 fresh post ideas tied to active campaigns.
- Client module UI: in Clients, add a "Daily Briefs" tab/module where users can view each day's brief and drill into the trends it discovered (with basic filters by date range, pillar, and campaign).
- Notifications: send a summary email to Owners/Strategists when new ideas are available (respecting quiet hours window in config).
- Tests: job fakes verifying scheduling, idempotency, and that notes are client-scoped.

### Step 2.6 — Guardrails and safety ✅ Done
- Strict filtering against `BrandBrain::weNeverSay[]` and prohibited topics before surfacing suggestions.
- Platform policy checks (e.g., link/hashtag rules per platform) applied post-generation with clear warnings instead of silent edits.
- PII/secrets scrubbed from prompts and logs; redact any incidentally returned secrets.
- Tests: regression test ensuring banned phrases never pass through suggestions (mirrors §12 acceptance style).

### Step 2.7 — Configuration, provider selection and ops ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.1):** per-org `organizations.ai_enabled` toggle +
  Owner-only Settings switch; effective flag = `config('ai.enabled') && $organization->ai_enabled`,
  centralized in `App\Services\AI\AiAccess` (no controller gates inline). Provider selection
  (`DeepSeekProvider` when `AI_ENABLED` + key, else `NullAiProvider`) was already in place. See
  `ai.md` › "AI control plane".
- `.env.example`: add `DEEPSEEK_API_KEY=`, `DEEPSEEK_MODEL=`, `AI_ENABLED=true`.
- `config/ai.php`: centralize provider selection (`provider = deepseek`, fallback `null`), model and limits. `AppServiceProvider` resolves `AiProvider` to `DeepSeekProvider` when `AI_ENABLED=true` **and** a non-empty `DEEPSEEK_API_KEY` is configured, otherwise falls back to `NullAiProvider` (never crash on missing key).
- Per-org enable toggle: add `organizations.ai_enabled` (boolean, default `false`) migration + Settings UI switch (Owner only); effective flag = `config('features.ai') && $organization->ai_enabled`. Document the precedence in `ai.md`.
- Basic metrics: log-derived counters (count, latency, error rate, retry rate) per method/model; consider a lightweight `AiUsageLog` table (Step 2.10) rather than relying on log scraping alone.
- Tests: feature test asserting the binding switches correctly across the four combinations of (`AI_ENABLED`, `DEEPSEEK_API_KEY` present) × (`organizations.ai_enabled`), and that the Settings toggle persists/authorizes correctly.

### Step 2.8 — Cost & usage controls ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.1):** `ai_usage_logs` existed (Phase 16); added
  `prompt_tokens`/`completion_tokens`, token-accurate recording on the agency text path via
  `RecordAiUsageAction` (DB transaction, never blocks the request), `organizations.ai_monthly_token_budget`
  + `AiUsageGuard`/`AiBudgetExceededException` (clean 422 "Monthly AI limit reached — contact your
  admin."), and the Owner-only Settings → AI usage panel (tokens/requests/cost vs budget). See
  `ai.md` › "AI control plane".
- New `ai_usage_logs` table (`org_id`, `client_id` nullable, `method`, `model`, `prompt_tokens`, `completion_tokens`, `cost_estimate`, `status`, `created_at`) populated by `App\Services\AI\DeepSeekProvider` after every call (success or failure) via a small `App\Actions\AI\RecordAiUsageAction` wrapped in `DB::transaction()` — never block/fail the user-facing request if usage logging itself errors (log a `warning` and continue).
- Per-org monthly budget: `organizations.ai_monthly_token_budget` (nullable int). `App\Services\AI\AiUsageGuard` checks remaining budget before dispatching a request; when exceeded, Actions return a typed `AiBudgetExceededException` surfaced to the UI as a clear, non-technical message ("Monthly AI limit reached — contact your admin") instead of a generic error.
- Settings UI: Owner-only "AI Usage" panel showing current-month tokens/requests/estimated cost and the configured budget, mirroring the existing Settings page layout/brand guidelines.
- Tests: unit tests for budget enforcement (under/at/over budget), usage recording on both success and failure paths, and a feature test for the Settings usage panel (authz + numbers rendered).

### Step 2.9 — Feedback loop & quality tracking ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.1):** polymorphic `ai_feedback` + `AiRating`,
  `RecordAiFeedbackAction` (idempotent per user/subject), `AiFeedbackController` thumbs up/down on
  trends/ideas/caption-sets, the implicit `Applied` signal when an idea becomes a draft, and
  `AiPerformanceService` acceptance-rate + top-dismissal-reason aggregation surfaced in Settings.
  The scheduled weekly digest email is deferred (gap recorded in `ai.md`). See `ai.md` ›
  "AI control plane".
- Add lightweight feedback capture on every AI suggestion surface (captions, ideas, trends): thumbs up/down + optional reason, stored on a polymorphic `ai_feedback` table (`org_id`, `subject_type`, `subject_id`, `user_id`, `rating`, `reason`, `created_at`) via `App\Actions\AI\RecordAiFeedbackAction`.
- Track "applied" vs "dismissed" suggestions implicitly (e.g., when `SuggestCaptionsForPostAction` writes the chosen suggestion into the post, log that as an implicit positive signal) to build an acceptance-rate metric per client/pillar without requiring explicit feedback.
- Weekly aggregate (reuses the Step 2.5 scheduler) surfaces an internal-only "AI performance" note per org: acceptance rate, top-performing pillars/platforms by acceptance, and most common dismissal reasons — helps the team tune prompts over time.
- Tests: unit tests for feedback recording and acceptance-rate aggregation; feature test for the thumbs up/down endpoint (authz-protected, idempotent per user/subject).

### Step 2.10 — Deeper context: performance-aware recommendations
- Extend `RecommendPostsAction`/`DiscoverTrendsAction` prompt context with lightweight historical signal already available in the app (no new vendor integration required): best-performing past `Post`s for the client (by whatever engagement/status data already exists), most-used pillars/campaigns, and posting cadence gaps in the upcoming calendar window.
- Add a `App\Support\AI\ClientContextBuilder` service responsible for assembling this context payload once per request (brand brain + recent posts + campaigns + calendar gaps), so prompt-building logic isn't duplicated across the three provider methods — bind/test it in isolation.
- "Best time to post" hint: derive a simple heuristic (e.g., platform-standard windows adjusted by the client's timezone from `TimezoneOptions`) rather than requiring real analytics data; clearly label it as a heuristic, not a guarantee, in the UI.
- Tests: unit tests for `ClientContextBuilder` assembling the right shape from factories, including the empty-history edge case (new client with no posts yet).

### Step 2.11 — Editor & dashboard polish
- Caption suggestions: show each option with a short "why this works" rationale tag (e.g., "Matches pillar: Education", "Trending hook format") derived from fields already present in `AiCaptionSuggestion`/`AiTrend` DTOs — reinforces trust without extra calls.
- Trends & Ideas panel: add a "Turn into post" quick action per idea that pre-fills a new draft `Post` via `RecommendPostsAction`'s persistence path, so a strategist can go from idea to scheduled draft in one click.
- Empty/error states: distinct, on-brand (per `.junie/brand-guidelines.md`) empty states for "AI disabled for this org", "budget exceeded" (Step 2.8), and "provider error — try again", instead of a blank panel.
- Tests: component/feature tests covering each distinct empty/error state and the "Turn into post" flow end-to-end.

### Step 2.12 — Standout differentiators (stretch, prioritize by client value)
- **Tone & brand-fit score**: before showing suggestions, run a second cheap/structured DeepSeek call (or a deterministic heuristic as a cheaper first pass) that scores each caption option 0–100 against the brand voice/pillars, surfaced as a small badge — makes "on-brand" tangible instead of just trusted.
- **Multi-variant A/B captions**: `SuggestCaptionsForPostAction` can tag two suggestions as an explicit "A/B pair" (same idea, different hook/CTA) when the post targets a platform that supports first-comment/alt variants, so strategists can test messaging.
- **Cross-client trend radar**: an org-level (not client-level) "Trend Radar" view aggregating `AiTrend` results across all of an org's clients in the same industry, so agencies spot patterns once and apply them everywhere — strictly scoped to the org's own clients, never cross-org.
- **Content calendar gap detection**: `DiscoverTrendsAction`'s scheduled run (Step 2.5) also flags upcoming calendar gaps (e.g., "no posts scheduled for Client X in the next 5 days") alongside trend ideas, turning the Daily Brief into a proactive account-health signal, not just inspiration.
- **Multi-language support**: `ClientContextBuilder` (Step 2.10) includes the client's primary audience language (new optional `clients.content_language` column, default `en`); prompts instruct DeepSeek to respond in that language, with English kept as the internal fallback for guardrail matching.
- **"AI Lab" playground (internal/Owner-only)**: a sandbox page to replay a prompt against the current brand brain with tweakable parameters (temperature-equivalent, max tokens) for the team to tune quality without touching code — writes to the same `ai_usage_logs`/`ai_feedback` tables so playground usage is tracked like any other call.
- Treat each bullet as independently shippable; do not block the Step 2.13 exit criteria on all of them — ship the ones with clearest client value first and record decisions/deferrals in `ai.md`'s Known Gaps section.

### Step 2.13 — "Done when"
- Editors can fetch trends and see 5–10 relevant ideas per client with platform tags, each with a plain-language rationale.
- From a post editor, clicking "Suggest captions" yields 3–5 on-brand caption options with hashtags/alt text, brand-fit badges, and never any `weNeverSay[]` phrases.
- Per-org AI is enabled only when both the platform flag and the org's own Settings toggle are on; disabling either immediately hides all AI UI/endpoints for that org.
- Monthly token budgets (when configured) are enforced with a clear, non-technical user-facing message when exceeded; usage is visible in Settings.
- No API keys, raw prompts, or other secrets appear in client-visible props or logs at any point.
- Tests for provider, Actions, usage/budget guard, feedback loop, and endpoints are green; `.junie/modules/ai.md` reflects every shipped sub-step.

---

## Phase 3 — Inbox Drafts ✅ Done (via MVP Phase M-9, 2026-11-12)

Goal: read comments via the adapter where available; AI drafts replies; a human sends. No auto-reply.

### Step 3.1 — Comment ingestion ✅ Done
- **Implemented via MVP M-9.1:** a dedicated `App\Contracts\CommentSource` seam (not the publish adapter)
  with `UploadPostCommentSource` for the seven comment-supporting networks + `NullCommentSource`; inbound
  comments stored against the post target as threaded `inbox_conversations` / `inbox_messages`. See
  `.junie/modules/inbox.md`.
- Extend the adapter interface with a read-comments capability where Upload-Post supports it.
- Store inbound comments against the relevant post/account.

### Step 3.2 — AI draft replies ✅ Done
- **Implemented via MVP M-9.3:** DeepSeek (not Grok) behind `App\Contracts\ReplyAssistant`; drafts are
  budgeted/metered/guardrailed and returned as text only. Sending is the separate, human-only
  `SendInboxReplyAction` — a test proves drafting never calls the vendor reply path.
- Same server-side pattern as Step 2.1: draft only, never sent automatically.
- Human must explicitly approve/send.

---

## Phase 4 — Second Adapter

Only build if a sold feature is missing that Upload-Post can't cover (e.g. TikTok CML licensing, native IG audio, DMs). Implement against the same `PublishAdapter` interface from Step 1.1 — this is the reason that interface exists.

## Phase 5 — Own Meta App

Only after Phase 1 has been stable for a quarter, or Graphy is sold to external agencies. Out of scope for now — no steps to plan yet.

---

## Phase 6 — Client Login Portal ✅ Done

Goal: an org (e.g. Graphy) can invite a named contact at one of its clients (e.g. Recommendo) to a
real, authenticated portal — distinct from the token-based, single-post `/review/:token` link from
Step 0.13 — where that contact logs in and sees everything scoped to their own client: posts in
flight, outstanding approvals, invoices, and the tasks they're expected to action. This builds on the
existing `Role::ClientReviewer` (scoped to one `client_id`, no billing visibility per §3) rather than
introducing a new role, but extends what that role is allowed to see/do now that it has its own login
instead of only a one-off review link.

### Step 6.1 — Client contact invitations ✅ Done
- New `ClientInvitation` model/migration: `client_id`, `email`, `invited_by_user_id`, hashed token,
  `expires_at`, `accepted_at`, `revoked_at`.
- `App\Actions\ClientInvitations\CreateClientInvitationAction` — wrapped in `DB::transaction()`,
  creates the invitation and sends `App\Mail\ClientInvitationMail` (Resend, mirrors
  `ClientReviewRequestedMail`'s branded HTML pattern) with a signed accept-invite link. Log the send
  (org id, client id, invited email — never the raw token) at `info`, and any mail failure at
  `warning`.
- `App\Policies\ClientInvitationPolicy` — Owner/Strategist only, scoped to their own org's clients.
- `ClientInvitationController` (`store`/`destroy` nested under `clients/{client}/invitations` and
  `invitations/{invitation}`) — thin: validates via `StoreClientInvitationRequest`, delegates to the
  Action, redirects. No separate `index` endpoint: like invoices/social accounts, pending/accepted
  invitations are loaded by `ClientController::show` and rendered inline in a "Portal access" tab on
  the client show page, letting an Owner/Strategist send or revoke one.
- **Done when**: PHPUnit tests cover sending an invitation, rejecting a duplicate pending invitation to
  the same email/client, and revoking one; and assert the invitation token is never present in a
  rendered page's JSON props (mirrors the Step 0.15 secret-leak test pattern). ✅ See
  `tests/Feature/ClientInvitationControllerTest.php` and `.junie/modules/client-portal.md`.

### Step 6.2 — Invitation acceptance and account creation ✅ Done
- `GET /client-invite/{token}` (public, outside `auth`/`verified` — mirrors the `review/{token}` route)
  shows an accept-invitation page; validates the token the same way `ReviewToken` does (hash lookup +
  expiry/revoked check, 404 on any mismatch).
- `POST /client-invite/{token}` — `App\Actions\ClientInvitations\AcceptClientInvitationAction`, wrapped
  in `DB::transaction()`: creates (or, if the email already has a `Role::ClientReviewer` user, reuses)
  a `User` with `role = Role::ClientReviewer` and `client_id` from the invitation, sets a password,
  marks `accepted_at`, logs the acceptance in the client's activity log.
- **Done when**: PHPUnit tests cover a fresh accept (new user created, scoped to the right client and
  org), an already-accepted/expired/revoked token being rejected, and the resulting user landing in
  `Role::ClientReviewer` with no cross-client access (reuses the isolation-test pattern from Step
  0.13).
- Implemented via `App\Actions\ClientInvitations\AcceptClientInvitationAction` (creates/reuses the
  `Role::ClientReviewer` user, sets password, marks `accepted_at`, logs at `info` — no client-level
  activity log model exists yet, so the acceptance is logged via `Log::info` instead),
  `AcceptClientInvitationRequest` (name + shared `passwordRules()`), and
  `ClientInvitationAcceptController` (`show`/`store`), which resolves the invitation the same way
  `ReviewController` resolves a `ReviewToken` (sha256 hash lookup + `isPending()`, 404 on any
  mismatch). Routes `GET`/`POST client-invite/{token}` are public. Frontend:
  `resources/js/pages/client-invite/show.tsx` mirrors `review/show.tsx`'s branded layout. See
  `.junie/modules/client-portal.md`.

### Step 6.3 — Client portal shell and navigation ✅ Done
- A distinct, client-facing layout (`resources/js/layouts/client-portal/`) per the brand guidelines —
  no internal-ops chrome (no "Needs attention"/Settings/other clients), just this client's identity and
  a nav for Posts, Approvals, and Invoices.
- `App\Http\Middleware\EnsureClientPortalAccess` (or a dedicated route middleware group under
  `/portal`) confirms the authenticated user is a `Role::ClientReviewer` and scopes every query in this
  phase by `auth()->user()->client_id` — never by a value taken from the request.
- **Done when**: a `Role::ClientReviewer` hitting any `/portal/*` route only ever sees their own
  `client_id`'s data, confirmed by a PHPUnit test attempting to access another client's post/invoice by
  guessing its id and asserting a 403/404.
- Implemented via `App\Http\Middleware\EnsureClientPortalAccess` (aliased `portal`, applied to a new
  `/portal` route group alongside `auth`/`verified`): rejects any non-`Role::ClientReviewer`/missing
  `client_id` with a 403, and generically 404s any `{post}`/`{invoice}` route-model binding whose
  `client_id` doesn't match the session's — so future portal routes inherit the isolation check just
  by binding those parameters. Ships with a `client-portal` layout (top nav, no sidebar, brand §7
  variant) and minimal scoped `portal.dashboard`/`portal.posts.show`/`portal.invoices.show` pages
  (full content lands in Steps 6.4-6.6). See `.junie/modules/client-portal.md`.

### Step 6.4 — Portal dashboard (needs-attention, client view) ✅ Done
- `GET /portal` — reuses `App\Actions\Home\GetNeedsAttentionItemsAction`'s shape but scoped to the
  logged-in contact's own `client_id`: posts `waiting_client` (outstanding approvals), recently
  `changes_requested`, and upcoming scheduled posts for their brand — deliberately no failed-publish or
  disconnected-account internals (those stay Owner/Strategist-only per §3).
- **Done when**: seeded fixtures for outstanding approvals and upcoming scheduled posts produce a row
  on this dashboard, and rows link into the portal's own post-detail view (Step 6.5), not the internal
  editor.
- Implemented via `App\Actions\Portal\GetPortalDashboardItemsAction` (scoped by
  `auth()->user()->client_id`, mirrors `GetNeedsAttentionItemsAction`'s shape:
  `waitingApprovals`/`changesRequested`/`upcomingPosts`, the latter reusing
  `GetUpcomingPostOccurrencesAction`'s org-timezone conversion), wired into
  `PortalDashboardController::index`, and rendered by `portal/dashboard.tsx` (same
  card/badge pattern as `pages/home.tsx`), with each row linking to `portal.posts.show`. See
  `.junie/modules/client-portal.md`.
### Step 6.5 — Portal posts and approvals ✅ Done
- `GET /portal/posts` (list, filterable by status) and `GET /portal/posts/{post}` (detail) — reuse the
  same policy scoping approach as `PostPolicy::viewCalendar`'s `ClientReviewer` branch, but now backed
  by a real session instead of a one-off `ReviewToken`.
- Approve / Request changes actions call the existing `TransitionPostStatusAction` (already supports a
  `Role::ClientReviewer` transitioning `waiting_client` → `approved`/`changes_requested`, and already
  blocks `internal_only` comments for that role) — no new transition logic needed, just a new
  authenticated entry point alongside the existing `/review/:token` one.
- **Done when**: a logged-in client contact can see every post currently `waiting_client` for their
  brand, approve or request changes on one, add a (non-internal) comment, and cannot see any other
  client's posts or any `internal_only` comment.
- Implemented via `App\Http\Controllers\Portal\PortalPostController` (`index`/`show`/`decide`/
  `comment`), `App\Http\Requests\Portal\PortalPostDecisionRequest` (mirrors `TransitionPostRequest`'s
  `PostPolicy::transition` authorization), routes `portal.posts.index`/`decide`/`comments.store`, and
  the `portal/posts/index.tsx`/`show.tsx` Inertia pages. `decide`/`comment` reuse
  `TransitionPostStatusAction`/`CreatePostCommentAction` as-is. See
  `.junie/modules/client-portal.md`.

### Step 6.6 — Portal invoices ✅ Done
- `GET /portal/invoices` — reuses `InvoicePolicy`'s scoping pattern, listing this client's invoices
  (status, amount, due date, a link/download for the invoice document) with no ability to create/edit
  — read-only, per §3's "no billing visibility beyond their own invoices" boundary for this role.
- **Done when**: a client contact sees only their own client's invoices (unpaid ones clearly flagged)
  and gets a 403/404 attempting to view another client's invoice id directly.
- Implemented via `App\Http\Controllers\Portal\PortalInvoiceController::index` (scoped by
  `auth()->user()->client_id`, no separate document/download field exists on `Invoice` yet so each
  row links to `portal.invoices.show` instead), the `portal.invoices.index` route, and
  `portal/invoices/index.tsx`/`show.tsx` rendering an "Unpaid" badge for any non-`paid` status.
  Isolation reuses `EnsureClientPortalAccess`'s existing `{invoice}` scoping (already 404s a
  mismatched `client_id`). See `.junie/modules/client-portal.md`.

### Phase 6 exit criteria
- An Owner/Strategist can invite a named contact at a client to the portal, and that contact can log
  in with their own credentials (no shared/token link).
- That contact's `/portal/*` session shows only their own client's posts, outstanding approvals, and
  invoices — verified by the cross-client isolation test from Step 6.3.
- The contact can action what's expected of them (approve/request changes, comment) without touching
  any internal-ops view (Home, Settings, other clients, billing beyond their own invoices).

---

## Phase 7 — Invoicing v2: Line Items, Branded PDFs & Reporting Foundation

Goal: an Owner/Strategist can build an invoice out of individual line items (billable or not,
priced or not), finalize it so the total (billable items + the client's retainer) is computed and
locked in, and hand the client a PDF that looks like Graphy's own brand rather than a manually
uploaded file — while leaving every line item queryable later by a future client-reporting module
("what did we do for you this month/quarter"). Builds on the existing `Invoice` module documented in
`.junie/modules/billing.md`; does not change the existing `draft → sent → paid` state machine, only
what happens inside `draft`.

### Step 7.1 — `InvoiceItem` model and migration ✅ Done
- New `invoice_items` table/model: `invoice_id` (FK, cascade delete), `org_id` (denormalized, mirrors
  `Invoice::org_id`, for simple org-scoped reporting queries later), `description` (text),
  `is_billable` (bool, default `true`), `price` (decimal 10,2, nullable — `null`/`0` when not
  billable), `quantity` (unsigned int, default 1), `sort_order` (unsigned int, for stable manual
  ordering in the UI), timestamps. Optional `post_id` (nullable FK to `posts`, `nullOnDelete`) so an
  item can optionally be tied back to the specific piece of content it bills for — useful context for
  Phase 8's reporting but not required to add an item.
- `Invoice::items()`: `hasMany` `InvoiceItem`, ordered by `sort_order`.
- `InvoiceItem::lineTotal()`: `is_billable ? price * quantity : 0` (a computed accessor, never
  stored).
- **Done when**: a migration + model + factory exist, and a model test confirms `lineTotal()` for a
  billable item with quantity > 1, a billable item with no price yet (treated as 0), and a
  non-billable item (always 0 regardless of price).

### Step 7.2 — Line item CRUD (restricted to `draft` invoices) ✅ Done
- `App\Actions\InvoiceItems\AddInvoiceItemAction` / `UpdateInvoiceItemAction` /
  `DeleteInvoiceItemAction` — each wrapped in `DB::transaction()`, each rejecting the operation with a
  `ValidationException` if the parent invoice's `status` is not `draft` (line items are frozen once an
  invoice is sent, matching how `UpdateInvoiceAction` already treats other fields as immutable after
  `draft`). `AddInvoiceItemAction` assigns the next `sort_order`.
- `App\Policies\InvoiceItemPolicy` (or extend `InvoicePolicy`): same Owner/Strategist-only,
  same-org rule as the rest of billing.
- `InvoiceItemController` (`store`/`update`/`destroy` nested under `invoices/{invoice}/items` and
  `invoice-items/{invoiceItem}`), each validated via a Form Request, delegating straight to its
  Action, redirecting back to `invoices.show`. Controller stays thin per AGENTS.md.
- **Done when**: PHPUnit tests cover adding/updating/deleting an item on a `draft` invoice (Owner
  allowed, Designer forbidden), and rejection (`422`) when attempted on a `sent`/`paid` invoice.

### Step 7.3 — Finalize action: compute and lock the total ✅ Done
- `App\Actions\Invoices\FinalizeInvoiceAction`: wrapped in `DB::transaction()`, callable only on a
  `draft` invoice with at least one item (reject otherwise via `ValidationException`). Sums every
  item's `lineTotal()`, adds the client's current `retainer_amount` (0 if null), writes the result
  into `Invoice::amount`, and flips a new boolean `Invoice::is_finalized` (migration: add
  `is_finalized` default `false`, plus `retainer_amount_snapshot` decimal nullable — stores the
  retainer value actually used, so a later change to the client's retainer never silently alters an
  already-finalized invoice's total).
- Once `is_finalized` is `true`, `AddInvoiceItemAction`/`UpdateInvoiceItemAction`/
  `DeleteInvoiceItemAction` (Step 7.2) and `UpdateInvoiceAction`'s `amount` field all reject further
  changes — finalizing is the explicit, one-way "lock it in" step before sending, distinct from
  `SendInvoiceAction`'s existing `draft → sent` transition (an invoice can be finalized while still
  `draft`, then sent).
- `InvoicePolicy`/`InvoiceController@finalize` (`POST /invoices/{invoice}/finalize`), with a
  "Finalize" button on `invoices/show.tsx` shown only while `draft` and `!is_finalized`, after which
  the items section renders read-only and shows the computed breakdown (items subtotal + retainer =
  total).
- **Done when**: a PHPUnit test creates a draft invoice, adds billable + non-billable items, finalizes
  it against a client with a retainer, and asserts `amount` equals billable subtotal + retainer,
  `retainer_amount_snapshot` is stored, and that adding another item afterward is rejected with a
  `422`.

### Step 7.4 — Line-item UI on the invoice detail page ✅ Done
- Extend `resources/js/pages/invoices/show.tsx`: an "Items" section (visible once the invoice exists,
  i.e. always — items can be added before or after the PDF/send flow, as long as the invoice is
  `draft` and not yet finalized) with an inline add-item form (description, billable toggle, price
  field shown/enabled only when billable, quantity), a list of existing items with inline edit/delete,
  a running subtotal, and — once finalized — the retainer line and grand total.
- Follow `.junie/brand-guidelines.md` for every new component (spacing, color, typography) rather than
  copying the PDF example's look verbatim.
- **Done when**: an Owner can add several items of both kinds to a draft invoice, see the live
  subtotal update, finalize, and see the items section become read-only with the final breakdown —
  verified with a PHPUnit feature test asserting the Inertia props shape (items array, `is_finalized`,
  computed subtotal) rather than a manual check.

### Step 7.5 — Branded, auto-generated invoice PDF ✅ Done
- Add `barryvdh/laravel-dompdf` (or equivalent) and a new Blade view,
  `resources/views/pdf/invoice.blade.php`, styled per `.junie/brand-guidelines.md` (logo, color
  palette, typography) rather than the attached example's layout — the example is reference content
  only (client/org details, line items table, billable vs non-billable distinction, retainer line,
  total, due date), not a design to copy.
- `App\Actions\Invoices\GenerateInvoicePdfAction`: renders the Blade view for a **finalized**
  invoice (reject with a `ValidationException` if not finalized — there must be a locked total to
  print) and stores the result via the existing `App\Contracts\AssetStorage`, populating the same
  `pdf_disk`/`pdf_path`/`pdf_url`/`pdf_original_filename`/`pdf_mime_type`/`pdf_size` columns the
  manual-upload flow (`UploadInvoicePdfAction`) already uses — so `invoices/show.tsx`'s existing PDF
  viewer needs no changes.
- `InvoiceController@generatePdf` (`POST /invoices/{invoice}/generate-pdf`) replaces manual upload as
  the primary path; leave `UploadInvoicePdfAction`/the upload route in place as a manual override for
  edge cases (e.g. a one-off adjusted PDF), per the "don't delete working functionality" principle —
  just stop presenting the upload form as the default on `invoices/edit.tsx` once a finalized invoice
  has items.
- **Done when**: a PHPUnit test finalizes an invoice with items, calls the generate-pdf action, and
  asserts a PDF was stored (mocking `AssetStorage` or asserting against the fake disk) with the
  correct `pdf_mime_type`; a manual visual check confirms the rendered PDF matches brand colors/
  typography rather than the attached example's styling.

### Step 7.6 — Module docs ✅ Done
- Update `.junie/modules/billing.md`: new `InvoiceItem` model/table, the `is_finalized`/
  `retainer_amount_snapshot` columns, the finalize action and its lock-out rules, the new
  item/finalize/generate-pdf routes, and a changelog entry. This keeps the module doc in sync per
  AGENTS.md's documentation workflow.

### Phase 7 exit criteria
- An Owner can build a draft invoice entirely from line items (mixing billable/non-billable), see a
  live subtotal, finalize it (locking in subtotal + retainer snapshot into `amount`), and generate a
  PDF styled with Graphy's own branding — with the full item history still queryable per-invoice for
  Phase 8.

---

## Phase 8 — Client Reporting from Invoice Line Items ✅ Done

Goal: once Phase 7's line items exist in volume, generate a monthly/quarterly report per client
summarizing the work captured in `invoice_items` (billable and non-billable) to present back to the
client — separate from the invoice/PDF itself. Deliberately not scoped in detail yet; revisit once
Phase 7 has shipped and there's real item data to design the report against.

### Step 8.1 — Report data query (not yet scoped) ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.3 / I-10.3):** `BuildClientReportSnapshotAction` on Phase 17's services (no second aggregation layer); the line-item work summary was left out (billing data).
- Likely an Action that queries `InvoiceItem` across a client's invoices within a date range,
  grouped by billable/non-billable and optionally by linked `post_id`. Exact shape, UI, and
  export format (PDF? on-screen? emailed?) to be defined when this phase starts.
- **Note (Phase 17):** Phase 17's per-client analytics drill-down (Step 17.5) and report exports
  (Step 17.7) are the internal foundation for this. Phase 8 then becomes the *client-facing* version
  (portal page + emailed PDF, scoped for `ClientReviewer`: own client only, no billing data, posts
  from "Waiting on client" onwards), adding the line-item work summary on top. Don't build a second
  aggregation layer.

---

## Phase 9 — Approval SLA & Reminders ✅ Done (via MVP Step M-6.5, 23 Oct 2026)

**Implemented via (2026-10-23, MVP Step M-6.5):** `organizations.approval_sla_hours`,
`PostApprovalSlaService`, Home + portal overdue surfacing, and `SendOverdueApprovalRemindersJob`
(`approvals:send-overdue-reminders`, daily). Tests `ApprovalSlaTest`. See `mvp.md` Step M-6.5.

Goal: stop posts from silently stalling in `waiting_client`/`changes_requested` by surfacing age and
nudging the relevant party — built entirely on the existing `Post`/`PostActivityLog`/notification
infrastructure, no new domain model required beyond a couple of config values.

### Step 9.1 — SLA threshold config
- Add `approval_sla_hours` (nullable int, default e.g. 48) to `organizations` (migration +
  `UpdateOrganizationSettingsAction`/`OrganizationSettingsUpdateRequest` extension, same Owner-only
  settings page from Step 0.15).
- **Done when**: an Owner can set/clear the threshold on the organization settings page and a PHPUnit
  test confirms it persists and defaults sensibly when unset.

### Step 9.2 — Overdue computation
- `App\Services\PostApprovalSlaService::isOverdue(Post $post): bool` — true when the post's current
  status is `waiting_client` or `changes_requested` and `now() - lastTransitionAt() >
  org.approval_sla_hours`, using the existing `PostActivityLog` timestamps (no new column needed) as
  the "entered this status at" source.
- **Done when**: a unit test covers a post just inside the threshold, just past it, and with no SLA
  configured (never overdue).

### Step 9.3 — Home + portal surfacing
- Extend `App\Actions\Home\GetNeedsAttentionItemsAction` with an `overdueApprovals` bucket (reusing
  `PostApprovalSlaService`) and add a matching "Overdue" badge on `resources/js/pages/home.tsx`'s
  waiting-approvals card.
- Extend `App\Actions\Portal\GetPortalDashboardItemsAction` the same way so the client contact also
  sees which of their own approvals are overdue — same badge treatment.
- **Done when**: seeded fixtures for an overdue and a non-overdue post each render correctly on both
  Home and the portal dashboard.

### Step 9.4 — Reminder notifications/job
- `App\Jobs\SendOverdueApprovalRemindersJob`, scheduled daily (mirrors Step 1.5's
  `publishing:poll-pending-statuses` scheduling pattern): for every overdue post, sends one
  in-app `Illuminate\Notifications\Notification` to the relevant internal owner (Strategist/Owner)
  reusing the existing notification-bell infrastructure (`.junie/modules/notifications.md`), and one
  `App\Mail\ClientApprovalReminderMail` (Resend, mirrors `ClientReviewRequestedMail`) to
  `Client::approval_email` when the post is `waiting_client`. Must not re-send more than once per
  24h per post (track via a `last_reminded_at` nullable timestamp on `Post`, migration).
- **Done when**: a PHPUnit test with a faked queue/mailer confirms exactly one reminder per overdue post
  per day, none for posts within the SLA, and none when the org has no SLA configured.

### Step 9.5 — Module docs
- Update `.junie/modules/home.md`, `.junie/modules/client-portal.md`, `.junie/modules/notifications.md`,
  and `.junie/modules/settings.md` with the new SLA config, overdue computation, and reminder job —
  plus a changelog entry in each, per AGENTS.md's documentation workflow.

### Phase 9 exit criteria
- A post left in `waiting_client` past the org's configured SLA shows as "Overdue" on both Home and
  the client portal, and triggers exactly one reminder per day to the right party until it moves.

---

## Phase 10 — Bulk Scheduling / Import ✅ Done (via MVP Step M-5.8, 23 Oct 2026)

_Implemented via MVP Step M-5.8: `ParseBulkPostImportAction` + `CreatePostsInBulkAction` +
`BulkPostImportController` + `BulkPostRow`/`BulkImportParseResult`, client-scoped CSV, all-or-nothing
drafts, template download. Tests `tests/Feature/Posts/BulkPostImportTest.php`. See `posts.md`._


Goal: let a Strategist create many posts (with per-target schedules) in one pass instead of one at a
time through the single-post editor from Step 0.10 — built as an additive wrapper around the existing
`CreatePostAction`/checklist, not a replacement for it.

### Step 10.1 — `BulkPostRow` DTO and parser
- `App\Support\Posts\BulkPostRow`: readonly DTO (`captions`, `hashtags`, `targets[]` — each with
  `socialAccountId`/`scheduledLocalDate`/`scheduledLocalTime`, `assetIds[]`, `campaignId`).
- `App\Actions\Posts\ParseBulkPostImportAction`: parses an uploaded CSV (columns: client, campaign,
  caption, hashtags, account handles + local date/time per account, asset filenames/ids) into a
  `Collection<BulkPostRow>`, collecting row-level validation errors (unknown account handle, bad
  date format, etc.) without throwing — rows are either all-valid or reported individually.
- **Done when**: a unit test feeds a fixture CSV with one valid row and one row with a bad account
  handle, and asserts one parsed `BulkPostRow` plus one reported row error.

### Step 10.2 — `CreatePostsInBulkAction`
- `App\Actions\Posts\CreatePostsInBulkAction`: wrapped in a single `DB::transaction()` for the whole
  batch (all-or-nothing, matching principle #5 — a half-imported batch is worse than none), calls the
  existing `CreatePostAction` per row so every post still gets independently-computed per-target
  `scheduled_at_utc` (reuses the Step 0.8 invariant unchanged) and the Step 0.10 checklist evaluation
  at `idea`/`draft` status only — bulk import never jumps straight to `scheduled`.
- Logs a summary (`org_id`, row count, success/failure count) at `info`, each row failure at
  `warning` with the row index (never raw caption/PII content in the log).
- **Done when**: a PHPUnit test imports 5 valid rows and asserts 5 `Post`+`PostTarget` rows created with
  correct independent local times (reusing the London/Dubai-style assertion), and a test with one bad
  row asserts the whole batch rolls back with a reported error.

### Step 10.3 — Import UI
- New `resources/js/pages/posts/bulk-import.tsx` (reachable from the client show page, mirrors the
  "Social accounts"/"Campaigns" inline-section entry points): file upload, a preview table showing
  parsed rows with inline per-row error highlighting from Step 10.1 before confirming, and a
  "Create N posts" submit calling Step 10.2's action.
- `PostController::bulkImport`/`bulkStore` (`GET`/`POST clients/{client}/posts/bulk-import`),
  `PostPolicy::create` reused for authorization (Designer still forbidden, per §3).
- **Done when**: a PHPUnit feature test uploads a fixture CSV through the endpoint and asserts the
  resulting posts appear on the client's post list/calendar.

### Step 10.4 — Module docs
- Update `.junie/modules/posts.md` with the bulk import flow, the CSV column contract, and a
  changelog entry.

### Phase 10 exit criteria
- A Strategist can upload a CSV of post rows for a client and get back a correctly-scheduled batch of
  draft posts (or a clear per-row error report with nothing partially created).

---

## Phase 11 — Content Recycling / Evergreen Reposting ✅ Done (via MVP Step M-5.8, 23 Oct 2026)

_Implemented via MVP Step M-5.8: `is_recyclable`/`recycle_after_days`/`recycled_from_post_id`,
`RecyclePostAction`, `posts.recycle` + `PostPolicy::recycle`, Home "Ready to recycle" bucket. (The
MVP copies the target accounts + overrides too, per I-4.7, rather than dropping targets.) Recurring
posts added on top (`recurrence`, `GenerateRecurringPostDraftsAction`). Tests
`tests/Feature/Posts/RecyclePostTest.php`. See `posts.md`._


Goal: let an Owner/Strategist flag a published post as reusable and get a low-friction path to
reschedule it later, without inventing a new content type — a recycled post is just a new `Post`
seeded from an old one's fields.

### Step 11.1 — `is_recyclable` flag + `RecyclePostAction`
- Add `is_recyclable` (bool, default `false`) and `recycle_after_days` (nullable int) to `posts`
  (migration), settable from the editor (Step 0.10's `posts/edit.tsx`) via a new checkbox + optional
  day count, persisted through the existing `UpdatePostAction`/`UpdatePostRequest`.
- `App\Actions\Posts\RecyclePostAction`: wrapped in `DB::transaction()`, given a published,
  `is_recyclable` post, creates a new `Post` in `idea` status copying caption/hashtags/music/location/
  assets (via `post_assets`) but **not** targets/schedule (the user must re-pick targets and dates,
  since accounts/timezones may have changed) — mirrors `CreatePostAction`'s field set minus targets.
- **Done when**: a PHPUnit test recycles a published post and asserts the new post is `idea` status with
  copied caption/hashtags/assets and zero targets, and that a non-recyclable post is rejected.

### Step 11.2 — Recycle suggestions on Home
- Extend `App\Actions\Home\GetNeedsAttentionItemsAction` with a `recyclingSuggestions` bucket: every
  `is_recyclable` published post where `published_at + recycle_after_days <= now()` and no later post
  has already been recycled from it (new nullable `recycled_from_post_id` FK on `posts`, set by Step
  11.1's action) — prevents the same post suggesting forever once acted on.
- `resources/js/pages/home.tsx`: new "Ready to recycle" card, each row with a one-click "Recycle"
  button that calls Step 11.1's action and redirects straight into the editor for the new draft to
  pick fresh targets/schedule.
- **Done when**: a seeded fixture past its recycle window produces a Home row, and clicking Recycle
  lands on the new draft's editor page with fields pre-filled.

### Step 11.3 — Module docs
- Update `.junie/modules/posts.md` and `.junie/modules/home.md` with the recycling fields, action, and
  Home bucket, plus a changelog entry.

### Phase 11 exit criteria
- A published post flagged recyclable produces a Home suggestion once its recycle window has passed,
  and recycling it one-clicks into a pre-filled new draft awaiting fresh scheduling.

---

## Phase 12 — Post & Engagement Analytics ✅ Done

Goal: turn the publish-result data already landing on `PostTarget` (Steps 1.4/1.5) plus vendor
engagement metrics (where Upload-Post/platform APIs expose them) into a per-client/campaign analytics
view — read-only reporting, no new publish/schedule logic.

### Step 12.1 — `PostTargetMetrics` model + sync ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.1):** `PostTargetMetric` + `SyncPostTargetMetricsAction` behind a separate `EngagementSource` contract.
- New `post_target_metrics` table/model: `post_target_id` (FK), `impressions`, `likes`, `comments`,
  `shares`, `fetched_at`, nullable (vendor may not return all fields per platform).
- Extend `App\Contracts\PublishAdapter` with an optional `metrics(PostTarget $target): ?PostTargetMetricsData`
  method (`NullPublishAdapter` returns `null`; `UploadPostAdapter` calls Upload-Post's analytics
  endpoint if/when available — if no such vendor endpoint exists, document the gap in
  `.junie/modules/publishing.md` and leave this step's sync as a no-op stub rather than guessing a
  shape).
- `App\Jobs\SyncPostTargetMetricsJob`, scheduled daily for every `published` target newer than e.g.
  90 days (mirrors Step 1.5/1.6's scheduled-command pattern), storing one new `PostTargetMetrics` row
  per sync (append-only history, not an upsert) so trends over time are queryable later.
- **Done when**: a unit test with a faked adapter response confirms a metrics row is stored per
  synced target, and the `NullPublishAdapter`/unavailable-vendor path is a safe no-op.

### Step 12.2 — Analytics dashboard ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.1):** the `engagement` section of `GetOrganizationAnalyticsSummaryAction` (`EngagementMetrics`).
- `App\Actions\Analytics\GetClientAnalyticsSummaryAction`: aggregates the latest `PostTargetMetrics`
  per post within a date range for one client, grouped by platform and by campaign, plus simple
  derived figures (total posts published, total engagement, best-performing post by engagement).
- `App\Policies\ClientPolicy::viewAnalytics` (Owner/Strategist/Designer/Viewer, same as
  `viewCalendar`), `AnalyticsController::index` (`GET clients/{client}/analytics`), and
  `resources/js/pages/analytics/index.tsx` (charts/cards following `.junie/brand-guidelines.md`,
  filterable by date range and campaign).
- **Done when**: seeded fixtures with a few synced metrics rows produce the expected aggregates in a
  PHPUnit feature test asserting the Inertia props shape.
- **Note (Phase 17):** if Phase 17 ships first, this step shrinks. Don't build a separate
  `GetClientAnalyticsSummaryAction`, controller or page. Fill the `engagement` section of
  `GetOrganizationAnalyticsSummaryAction` (Step 17.3), which then shows up on the org analytics page,
  the client drill-down (Step 17.5), saved reports and exports for free. Step 15.4's platform
  `engagement` section reads from the same shared metric service.

### Step 12.3 — Module docs ✅ Done
- New `.junie/modules/analytics.md` following the standard template (purpose, models, actions,
  routes, business rules, tests, known gaps — explicitly noting whichever vendor metrics endpoint
  turned out to be unavailable), and add its row to `.junie/modules/README.md`'s module table.

### Phase 12 exit criteria
- A client's analytics page shows aggregated engagement by platform/campaign over a selectable date
  range, built entirely from metrics synced from the existing publish adapter — no manual data entry.

---

## Phase 13 — Public API & Outbound Webhooks

Goal: let an agency's own tools (CRM, project management, Zapier/Make) react to Graphy events, using
the same adapter-style "swappable, testable boundary" pattern already established by `PublishAdapter`.

### Step 13.1 — API token model
- New `App\Models\ApiToken` (or Laravel Sanctum personal access tokens, if already a dependency):
  `org_id`, `name`, hashed token, `last_used_at`, `revoked_at`. Owner-only CRUD on a new
  "API access" settings tab (mirrors Step 0.15's organization settings page).
- **Done when**: an Owner can issue/revoke a token from settings, and the raw token is shown exactly
  once (never persisted/logged in plaintext) — a PHPUnit test asserts this.

### Step 13.2 — `WebhookSubscription` model + dispatcher
- New `webhook_subscriptions` table/model: `org_id`, `url`, `event` (enum:
  `post.status_changed`, `invoice.sent`, `invoice.paid`), `secret` (for HMAC signing, mirrors Step
  1.5's inbound signature pattern but outbound), `is_active`.
- `App\Services\WebhookDispatcher::dispatch(string $event, array $payload, int $orgId)`: queued HTTP
  POST to every active subscription matching `event`/`org_id`, signed the same way Upload-Post signs
  inbound webhooks (`X-Graphy-Signature: sha256=HMAC(secret, timestamp.body)`), with retry/backoff via
  Laravel's queue retry mechanism. Called from `TransitionPostStatusAction` (on any status change) and
  `SendInvoiceAction`/`MarkInvoicePaidAction` — each a one-line addition, no business logic duplicated.
- **Done when**: a PHPUnit test with `Http::fake()` confirms a `post.status_changed` transition fires a
  correctly-signed POST to a registered subscription, and an inactive/non-matching subscription never
  receives it.

### Step 13.3 — Minimal public read API
- `GET /api/v1/posts`, `GET /api/v1/clients` (Sanctum/token-authenticated, scoped to the token's
  `org_id`, read-only, paginated) — thin `Api\*Controller` classes delegating to existing Eloquent
  queries/policies, no new business logic.
- **Done when**: a PHPUnit test confirms a valid token only ever sees its own org's data and an invalid/
  revoked token gets a 401.

### Step 13.4 — Module docs
- New `.junie/modules/api.md` (token model, webhook subscriptions/events, endpoints, signing scheme,
  known gaps) and its row in `.junie/modules/README.md`.

### Phase 13 exit criteria
- An Owner can issue an API token and register a webhook URL for `post.status_changed`, and a real
  status transition delivers a correctly-signed payload to that URL, confirmed against a test
  receiver.

---

## Phase 14 — White-Label / Reseller Branding

Goal: let an agency reselling Graphy to its own clients present the client portal and invoice PDFs
under its own brand, without duplicating the org/workspace schema (per the spec §13 reminder to keep
billing/org schema flexible for this).

### Step 14.1 — `OrganizationBranding` fields
- Add nullable `logo_path`, `primary_color`, `accent_color` to `organizations` (migration), uploaded
  via the existing `App\Contracts\AssetStorage` (same storage abstraction as Step 0.7's assets — no
  new storage code). Owner-only, on the organization settings page (Step 0.15).
- **Done when**: an Owner can upload a logo and pick colors, and a PHPUnit test confirms the stored path/
  colors round-trip and the logo is served from the configured `AssetStorage` disk.

### Step 14.2 — Apply branding to client portal and PDFs
- `resources/js/layouts/client-portal/*` reads the org's branding (passed as an Inertia shared prop,
  same mechanism as the preview banner from Step 0.14) to swap the logo and CSS custom properties for
  `primary_color`/`accent_color`, falling back to Graphy's own brand (`.junie/brand-guidelines.md`)
  when unset.
- `resources/views/pdf/invoice.blade.php` (Step 7.5) reads the same fields when rendering, falling
  back the same way.
- **Done when**: a PHPUnit/Dusk-style snapshot (or at minimum a feature test asserting the branding
  values are present in the portal's Inertia props and the PDF action passes them to the view) shows
  a non-default org's branding both in the portal and in a generated PDF, while an org with no
  branding set renders identically to today's defaults.

### Step 14.3 — Module docs
- Update `.junie/modules/settings.md`, `.junie/modules/client-portal.md`, and `.junie/modules/billing.md`
  with the new branding fields and fallback rule, plus a changelog entry in each.

### Phase 14 exit criteria
- An org with custom branding set sees its own logo/colors throughout the client portal and on every
  generated invoice PDF; an org without branding set is visually unchanged from today.

---

## Phase 15 — Super Admin Module (platform analytics, reporting CRUD, org onboarding)

Goal: give a platform-level super user (not tied to any single organization) a dedicated area to
onboard new organizations (there is currently **no org signup system** — orgs today only ever exist
via `OrganizationSeeder`) and to view/manage analytics & reporting data across every org. This is a
genuinely new, substantial module, so it's broken into six sub-steps that can ship independently.
A standalone seeder for the first super admin account already exists:
`database/seeders/SuperAdminUserSeeder.php` (run with
`php artisan db:seed --class=Database\Seeders\SuperAdminUserSeeder`), seeding
`alexander.watson.dev@gmail.com` / `password` with `role = Role::SuperAdmin` and `org_id = null`.

### Step 15.1 — Super admin identity, guard & access control ✅ Done
- `App\Enums\Role::SuperAdmin` already added (org-less — `org_id` stays `null` for these users, since
  a super admin is platform staff, not a member of any single organization's workspace).
- `App\Models\User::isSuperAdmin(): bool` already added. Add
  `App\Policies\SuperAdminPolicy` (or a simple `Gate::before` in `AppServiceProvider` granting
  super admins every ability — simplest, matches principle #4's "swappable where suitable", but a
  `Gate::before` is appropriate here since super admin truly bypasses per-org policies rather than
  needing its own swappable contract) plus an `App\Http\Middleware\EnsureUserIsSuperAdmin` middleware
  registered as `super_admin` in `bootstrap/app.php`, applied to a new `super-admin/*` route group in
  `routes/web.php` (mirrors how `routes/web.php` already groups client-portal routes separately from
  staff routes).
- Login stays on the existing Fortify flow (no separate login page) — after authentication,
  `HandleInertiaRequests` (or a new redirect in the post-login handler) sends a `role === SuperAdmin`
  user to `/super-admin` instead of the normal org `/home`, since they have no org-scoped Home to see.
- **Done when**: a PHPUnit test confirms a super admin can reach every `super-admin/*` route while a
  normal Owner/Strategist gets a 403, and logging in as the seeded super admin redirects to
  `/super-admin` instead of `/home`.

### Step 15.2 — Organization onboarding / signup (the missing piece) ✅ Done
- New public-facing signup flow — today organizations are only ever created by a seeder, so this adds
  the first real "create an organization" path in the app.
  - **15.2.1 — `RegisterOrganizationAction`** ✅ style: `App\Actions\Organizations\RegisterOrganizationAction`,
    wrapped in `DB::transaction()` (principle #5 — creates an `Organization` row **and** its first
    `User` with `role = Owner` together, so a half-created org/owner pair can never exist), taking
    org name/timezone/currency (reusing `TimezoneOptions`/`CurrencyOptions` from Step 0.x) plus the
    first user's name/email/password. Logs the new org id + owner email at `info` (never the password).
  - **15.2.2 — Public signup controller/pages**: `App\Http\Controllers\Organizations\RegistrationController`
    (`GET`/`POST /register-organization`, outside any auth middleware, mirrors Fortify's own
    registration controller pattern) with `OrganizationRegistrationRequest` validating a unique email
    across `users` and reasonable org name length. `resources/js/pages/organizations/register.tsx`
    (branded per `.junie/brand-guidelines.md`) — on success, logs the new user in and redirects to
    `/home` as a normal Owner, so day-to-day use of the app is completely unchanged after signup.
  - **15.2.3 — Email verification / welcome email**: reuse Fortify's existing email verification
    flow for the new owner account, plus a new `App\Mail\OrganizationWelcomeMail` (Resend, mirrors
    `ClientReviewRequestedMail`'s pattern) sent once the org is created.
  - **Done when**: a PHPUnit feature test posts valid signup data and asserts an `Organization` + Owner
    `User` are created together, the user is logged in and redirected to `/home`, a duplicate email is
    rejected with a validation error and no partial org is created, and the welcome email is queued.

### Step 15.3 — Super admin organization CRUD ✅ Done
- `App\Actions\SuperAdmin\{ListOrganizationsAction,UpdateOrganizationAction,SuspendOrganizationAction,DeleteOrganizationAction}`
  — read/update/suspend/delete any organization. Suspend adds a nullable `suspended_at` on
  `organizations` (migration) and a new `App\Http\Middleware\EnsureOrganizationNotSuspended` applied
  org-wide (locks out all org users with a clear "contact support" page) — soft, reversible, and
  distinct from hard delete.
- `App\Http\Controllers\SuperAdmin\OrganizationController` (`index`/`show`/`update`/`suspend`/
  `destroy` under the `super-admin` route group from Step 15.1) + `resources/js/pages/super-admin/
  organizations/{index,show}.tsx` — a searchable/paginated table (org name, owner, plan/health
  summary, created date, status) and a detail page.
- **Done when**: PHPUnit tests cover listing/searching, updating an org's details, suspending (locked-out
  org users get the support page, super admin unaffected), and deleting (cascades per existing FK
  `cascadeOnDelete()` rules already on every org-scoped table).

### Step 15.4 — Cross-org analytics & reporting dashboard ✅ Done
- `App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction`: platform-wide figures — total orgs,
  active vs. suspended, total posts published (all-time and last 30 days), total clients, MRR-style
  total from `invoices`/`invoice_items` (reusing the Step 7 billing data, not duplicating it),
  signups-over-time, and — once Phase 12 lands — aggregated engagement across every org's
  `PostTargetMetrics`. Each figure built from a simple grouped/aggregated query against existing
  tables; no new domain model needed here, this phase is purely a read/reporting layer over data the
  rest of the app already produces.
- `App\Http\Controllers\SuperAdmin\AnalyticsController` (`GET /super-admin/analytics`, filterable by
  date range) + `resources/js/pages/super-admin/analytics/index.tsx` (charts/cards, same visual
  language as Phase 12's client-facing analytics page, per `.junie/brand-guidelines.md`) — this is the
  "all things analytics and reporting" surface, i.e. the platform-wide counterpart to Phase 12's
  per-client view.
- Add a lightweight per-org drill-down (`GET /super-admin/organizations/{organization}/analytics`)
  reusing Step 15.4's aggregation scoped to one org, for support/sales conversations.
- **Done when**: seeded fixtures across two orgs with posts/invoices produce correct platform-wide
  totals and correct per-org drill-down totals in a PHPUnit feature test asserting the Inertia props
  shape.

### Step 15.5 — Reporting CRUD (saved/custom reports) ✅ Done
- New `super_admin_reports` table/model (`name`, `filters` JSON — date range, org ids, metric
  selection —, `created_by` FK to `users`): lets the super admin save a named report configuration
  (built from Step 15.4's filters) and re-run it later rather than re-selecting filters every time.
- `App\Actions\SuperAdmin\{CreateReportAction,UpdateReportAction,DeleteReportAction,RunReportAction}`
  (`RunReportAction` just re-invokes `GetPlatformAnalyticsSummaryAction` with the saved filters — no
  duplicated aggregation logic) wrapped per principle #5 where they touch more than a single row.
- `App\Http\Controllers\SuperAdmin\ReportController` (full CRUD + a `run` endpoint) and
  `resources/js/pages/super-admin/reports/{index,edit}.tsx` — list of saved reports, a filter-builder
  form, and a "run" view reusing Step 15.4's chart/card components.
- **Done when**: PHPUnit tests cover creating, updating, deleting a saved report, and running one returns
  the same totals `GetPlatformAnalyticsSummaryAction` would produce directly for the same filters.

### Step 15.6 — Module docs ✅ Done
- New `.junie/modules/super-admin.md` following the standard template (purpose, models — `Role::SuperAdmin`,
  `organizations.suspended_at`, `super_admin_reports` —, Actions/Controllers, routes, the org
  onboarding/signup flow, business rules, known gaps) and its row in `.junie/modules/README.md`'s
  module table. Also update `.junie/modules/organizations-users.md` and `.junie/modules/auth.md` with
  the new signup flow and the `SuperAdmin` role respectively.

### Phase 15 exit criteria
- `alexander.watson.dev@gmail.com` (seeded via `SuperAdminUserSeeder`) can log in, land on a
  super-admin dashboard, onboard a brand-new organization through the new public signup flow (the
  first time this has ever been possible without a seeder), manage (view/update/suspend/delete) any
  existing organization, and view/save/run cross-org analytics & reporting — all without ever being a
  member of any single organization.
- Implemented 2026-10-17 — see `.junie/modules/super-admin.md`. Additions beyond the plan: Fortify's
  org-less `/register` replaced by the org signup (old URL redirects); `MustVerifyEmail` enforced
  (invite accepts + super-admin onboarding count as verification, existing users grandfathered);
  super-admin onboarding on a customer's behalf (set-password welcome email); reinstate action;
  suspension pauses publishing/reminders/AI briefs; delete removes members (users.org_id is
  nullOnDelete); preview fixture protected and excluded from analytics; per-currency money;
  relative report ranges + CSV export. Post-signup redirect is `/dashboard` (the Owner landing
  page), not `/home`.

---

## Phase 16 — AI Image Studio (on-brand images for post ideas)

Goal: turn an `AiPostIdea` (or a draft `Post`) into a small set of on-brand images the team can pick
from, refine and drop straight into the post — grounded in the idea's caption, the campaign and the
client's Brand Brain (palette, imagery style, typography, logo notes **and real reference images**).
AI suggests and drafts; it never attaches, approves or publishes on its own. A human always picks.

Provider decision (2026-10-17): **Google Gemini image models** behind a new `App\Contracts\ImageProvider`
— Nano Banana 2 (Gemini 3.1 Flash Image) as the fast/cheap **draft** tier, Nano Banana Pro (Gemini 3
Pro Image) as the **final** tier. Chosen for multi-reference input (up to 14 images on Pro), strong
text rendering and low per-image cost at 1K. **GPT Image 2** is the planned second adapter (Step
16.12) for text-heavy designs. DeepSeek stays the "art director": it writes the structured image
brief; it never generates pixels. Verify exact model IDs/pricing against the vendor docs at build time
and record them in `ai.md`.

Optimisation principles for this phase (apply to every step):
- **Brief once, render many.** One DeepSeek call per set produces a structured `AiImageBrief`; every
  variant reuses it. Cached by `brief_hash` (idea + brand fingerprint + art-direction overrides).
- **Draft cheap, finalise once.** Sets render at the draft tier (1K). Only the variant a human picks
  is re-rendered at the final tier ("Make final"), so Pro spend tracks real usage, not exploration.
- **Never pay twice.** Every variant has an idempotency key; double clicks, job retries and
  page refreshes never trigger a second vendor call for the same variant.
- **Don't starve publishing.** Image jobs run on a dedicated `ai-images` queue with their own worker
  concurrency, so a burst of generation never delays `PublishPostJob`.
- **Small payloads.** Reference images are normalised once (long edge ≤ 1024px, WebP/JPEG) and
  reused; we never send originals or re-encode on every call.

### Step 16.1 — `ImageProvider` contract, DTOs, Null provider, config ✅ Done
- `App\Contracts\ImageProvider`:
  - `generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage`
  - `edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage`
  - `supports(ImageCapability $capability): bool` (e.g. `MultiReference`, `TextRendering`, `Edit`)
- DTOs under `App\Support\AI\Images\` (readonly, PHPStan-typed): `AiImageBrief` (subject,
  composition, mood, `palette` as named colours + tokens, style, `textOverlay` nullable string,
  `avoid[]`, `referenceAssetIds[]`), `ImageRenderOptions` (`ImageQualityTier` enum `Draft|Final`,
  `ImageAspectRatio` enum, seed nullable), `ReferenceImage` (mime, bytes, role: `Logo|Style|Product`),
  `GeneratedImage` (mime, bytes, width, height, provider, model, `providerRequestId`, `costEstimate`).
- `App\Enums\ImageAspectRatio` (`Square_1_1`, `Portrait_4_5`, `Story_9_16`, `Landscape_16_9`,
  `Link_1_91_1`) with `forPlatform(Platform $p): self` defaults (Instagram feed → 4:5, TikTok → 9:16,
  LinkedIn/Facebook → 1:1) — kept on the enum, not in the UI.
- `App\Services\AI\Images\NullImageProvider` returns a deterministic placeholder (solid brand-tone PNG
  of the requested ratio) so local dev, preview mode and tests work with no key.
- `config/ai.php` → new `images` block: `provider` (env `AI_IMAGE_PROVIDER`, `gemini|openai|null`,
  default `null`), `gemini.api_key` (`GEMINI_API_KEY`), `gemini.draft_model`, `gemini.final_model`,
  `timeout` (90), `max_variants` (4), `max_references` (8), `queue` (`ai-images`). `.env.example` updated.
- `AppServiceProvider` binds `ImageProvider` to the real adapter only when `ai.enabled` **and** the
  provider's key is set, otherwise `NullImageProvider` (same rule as `AiProvider`, Step 2.7).
- **Done when**: PHPUnit covers the binding matrix (enabled × key present × provider), DTO
  construction, `ImageAspectRatio::forPlatform()` for every `Platform` case, and the Null provider
  returning an image with the requested dimensions.

### Step 16.2 — Storage for generated bytes + asset provenance ✅ Done
- `AssetStorage` only accepts `UploadedFile` today. Add
  `storeContents(string $contents, string $mimeType, string $directory, string $extension): array`
  (same return shape as `store()`), implemented in `LocalAssetStorage` (and the future S3 driver).
- `AssetSource::Ai` (`'ai'`, label "AI generated"). Migration on `assets`: `ai_image_variant_id`
  (nullable FK), `is_ai_generated` (bool, default false, indexed). Reuse the existing, unused
  `variant_group_id` to group a generated set — no new grouping column.
- `App\Services\AI\Images\ImageNormalizer`: resizes/encodes reference images and generated outputs
  (long edge cap, WebP for previews, original kept for the chosen asset), writes `width`/`height`.
  Add `intervention/image` (GD driver) — no extra system binaries.
- **Done when**: tests cover `storeContents()` (path layout `ai-images/{org}/{client}/…`, returned
  shape), normaliser output dimensions/mime, and that `AssetSource::Ai` renders its label.

### Step 16.3 — Data model: generation sets and variants ✅ Done
- `ai_image_generations` (one per "Generate" click): `org_id`, `client_id`, `ai_post_idea_id`
  nullable, `post_id` nullable, `requested_by`, `brief` (json, the serialized `AiImageBrief`),
  `brief_hash`, `provider`, `model`, `quality_tier`, `aspect_ratio`, `variant_count`, `status`
  (`ImageGenerationStatus`: `queued|generating|ready|partially_failed|failed`), `cost_estimate`,
  timestamps. Index `(client_id, created_at)`.
- `ai_image_variants`: `generation_id`, `position`, `status` (`queued|generating|ready|failed`),
  `asset_id` nullable, `parent_variant_id` nullable (refine/final lineage), `quality_tier`,
  `idempotency_key` (unique), `provider_request_id`, `duration_ms`, `error_code` nullable
  (`safety_blocked|timeout|provider_error|quota_exceeded`), `selected_at`, `discarded_at`.
- Models `AiImageGeneration`/`AiImageVariant` with typed `@property` docblocks and enum casts;
  `AiImageGenerationPolicy`: Owner/Strategist generate and select (reusing `AiController::authorizeAiUse`
  rules); Designer may view sets and download; **ClientReviewer and Viewer get nothing** — they only
  ever see the final `Asset` once it's attached to a post at "Waiting on client" or later.
- Status tones added to `lib/status.ts` (generation: queued → neutral, generating → info, ready →
  success, partially_failed → warning, failed → danger).
- **Done when**: migration/factory tests, policy tests per role (incl. ClientReviewer denied on
  every endpoint), and the unique `idempotency_key` constraint asserted.

### Step 16.4 — Brand Brain: visual references ✅ Done
- Brand Brain gets a "Visual references" tab: pick up to `max_references` existing client `Asset`s
  (images only) and tag each as **Logo**, **Style** (past approved posts/mood) or **Product**.
  Pivot `brand_brain_reference_assets` (`brand_brain_id`, `asset_id`, `role`, `position`).
- On save, `SyncBrandReferenceAssetsAction` (in `DB::transaction()`) stores a normalised
  ≤1024px copy per reference once (Step 16.2) so generation never re-processes originals.
- `ClientContextBuilder::fingerprint()` includes reference asset ids + `visual` fields so cached briefs
  invalidate when the brand's look changes.
- UI: grid of chosen references with role `Badge`s (lilac/electric label tags), drag to reorder,
  "Add from assets" sheet reusing `SearchClientAssetsAction`. `EmptyState`: "No references yet." /
  "Add a logo and two or three posts you love. We'll match the look." / "Add references".
- **Done when**: tests for the action (limit enforced, non-image assets rejected, cross-client assets
  rejected), fingerprint changing on reference change, and the tab's authz.

### Step 16.5 — Image brief: DeepSeek as art director ✅ Done
- Add `draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief` to `AiProvider`
  (`ImageBriefInput` = idea title/description/suggested caption, campaign, target platforms,
  optional user art-direction overrides). Implement in `DeepSeekProvider` with strict JSON schema +
  the existing one-retry/`shapeError()` path; `NullAiProvider` returns a deterministic brief built
  from Brand Brain fields so the feature still works without DeepSeek.
- Prompt rules: hashtags are **excluded** (no visual value); `hashtag_policy.never_use` and
  `voice.do_nots` feed `avoid[]`; palette comes from `visual.color_palette` as named colours;
  `imagery_style` and `logo_usage_notes` are passed verbatim; default `textOverlay = null` (caption
  carries the words) unless the user turns on "Add headline text".
- Brief passes through `AiGuardrailService` (banned phrases, prohibited topics, PII scrub). Never
  asks for real public figures or third-party logos.
- `DraftImageBriefAction` caches the brief by `brief_hash` (24h) — regenerating a set with the same
  inputs costs zero DeepSeek tokens.
- **Done when**: HTTP-faked tests for schema enforcement/retry, guardrail regression (a `never_use`
  phrase never reaches the brief), hashtags absent from the brief, and cache hit on identical input.

### Step 16.6 — Gemini adapter ✅ Done
- `App\Services\AI\Images\GeminiImageProvider` implements `ImageProvider` over the Gemini API:
  maps `ImageQualityTier` → draft/final model, `ImageAspectRatio` → the API's ratio parameter,
  `ReferenceImage[]` → inline image parts (role-labelled in the prompt: "Image 1 is the logo…"),
  and renders `AiImageBrief` into a single, ordered prompt (subject → composition → style →
  palette → avoid).
- Errors map to typed exceptions: `ImageSafetyBlockedException`, `ImageProviderTimeoutException`,
  `ImageProviderUnavailableException` (5xx/429 after backoff with jitter, max 2 retries).
- Logging: `info` start/end with org/client/generation/variant ids, model, tier, `duration_ms`,
  `cost_estimate`; `warning` on retry/safety block; `error` on final failure. **Never** log the
  prompt, the brief text, image bytes or the key.
- **Done when**: `Http::fake()` tests for request shape (model per tier, ratio, reference parts
  count), each error mapping, retry/backoff, and a log-assertion test proving no prompt/key leaks.

### Step 16.7 — Orchestration: generate a set ✅ Done
- `GenerateImageSetAction` (controller → Form Request → this Action): checks quota (Step 16.11) and
  the per-org rate limiter (`RateLimiter::for('ai-images')`, e.g. 10 sets/min), drafts/fetches the
  brief, then in one `DB::transaction()` creates the `AiImageGeneration` + N `AiImageVariant` rows
  (default 4, max `max_variants`), and dispatches a `Bus::batch()` of `GenerateImageVariantJob`s on
  the `ai-images` queue **after commit**.
- `GenerateImageVariantJob`: `ShouldBeUnique` on variant id, `tries = 2`, `timeout = 120`; skips if
  the variant already has an asset (idempotent); on success stores via `storeContents()`, creates the
  `Asset` (`source = ai`, `is_ai_generated = true`, `variant_group_id = generation uuid`), marks the
  variant `ready` — all in one transaction. Batch `finally` recomputes the set status
  (`ready`/`partially_failed`/`failed`).
- Variants render with distinct seeds so a set shows real range (composition/crop), not four
  near-duplicates.
- `POST clients/{client}/ai/images` and `POST ai-post-ideas/{idea}/images` (thin), plus
  `GET ai-image-generations/{generation}` returning a lean status payload for polling.
- **Done when**: tests cover happy path (4 variants → 4 assets), partial failure (1 safety block →
  `partially_failed`, the other 3 usable), double-submit creating one set, job retry not creating a
  second asset, rate-limit and quota rejections, and jobs landing on the `ai-images` queue.

### Step 16.8 — Iterate: refine, regenerate, make final ✅ Done
- `RefineImageVariantAction`: short instruction ("warmer light", "remove the cup") → `edit()` using
  the variant as source; new child variant (`parent_variant_id`) in the same set.
- `RegenerateImageVariantAction`: same brief, new seed, replaces a failed or unloved tile.
- `FinalizeImageVariantAction`: re-renders the chosen draft at the **final** tier using the draft as
  a reference (keeps the composition people picked), marks it `selected_at`.
- Each is one queued job through the same pipeline (idempotency, quota, logging).
- **Done when**: tests for lineage, final tier/model used only on finalise, and quota counted per call.

### Step 16.9 — Image Studio UI ✅ Done (browser visual check pending)
Lives on the idea detail page (`resources/js/pages/clients/ai-post-idea.tsx`) and as a tab in the
post editor's AI panel. Follows `.junie/brand-guidelines.md`; no Signal Orange anywhere in the studio
(data screen), no patterns or peeks.

- **Header:** `PageHeader` with DM Mono eyebrow `IMAGE STUDIO` and headline "Make it visual." — the
  idea's caption shown as a quoted, collapsible line beneath so people see what the image serves.
- **Art direction panel** (`Panel`, eyebrow `ART DIRECTION`): prefilled from the brief, editable
  before generating — Subject (textarea), Mood, Style, palette **swatches** read from Brand Brain
  (toggle a colour off to exclude it), "Add headline text" switch (reveals a short text input with a
  live character count), and the reference images as small thumbnails with their role badges. A
  "Reset to brand defaults" ghost button. Changing any field updates the `brief_hash` client-side so
  the button can say "Generate" vs "Generate again".
- **Format & size:** segmented teal-pill control for aspect ratio, preselected from the idea's first
  platform; variants 2 / 4 toggle. A mono caption under the Primary button shows cost in plain terms:
  "4 drafts · 36 of 200 images left this month."
- **One Primary action:** "Generate images" (Midnight Ink). Everything else is secondary/ghost.
- **Results grid:** tiles in the chosen ratio, 2 columns on mobile / 4 on desktop. Pending tiles are
  calm skeletons with a mono "Drafting…" label (no spinner storm; respects `prefers-reduced-motion`).
  Tiles fill in **as each job finishes** via Inertia v3 `usePoll` (2s while anything is pending,
  stops automatically when the set settles). Each tile carries a `StatusPill` only when not ready.
- **Tile actions** (visible on hover/focus, always visible on touch): Select (teal check), Refine
  (popover with one input + quick chips: "Brighter", "Closer crop", "More space for text"),
  Regenerate, Download. Selected tile gets a 2px teal ring.
- **Lightbox** (`Dialog`, `rounded-2xl`): large preview beside a **platform preview** — the image in
  a lightweight Instagram/LinkedIn frame with the real caption, so the team judges it as it'll
  appear. Arrow keys move between variants, `Enter` selects, `R` opens refine.
- **Selection bar:** sticky bottom bar when ≥1 tile is selected: "2 selected" + Secondary "Make
  final" + Primary "Use in post". On an idea without a post yet, "Use in post" creates the draft via
  the existing `RecommendPostsAction::persist()` path, then attaches.
- **Earlier sets:** ruled rows under the grid (date, format, count, who generated) — expand to
  revisit; discarded tiles hidden by default.
- **States:** `EmptyState` before the first run — "No images yet." / "Pick a format and we'll
  draft a set in your client's style." / "Generate images". Distinct, friendly errors: safety block —
  "We couldn't make that one. Try softer wording." · quota — "You've used this month's images. Your
  owner can raise the limit." · provider down — "Image drafting is taking a break. Try again in a
  minute." · AI disabled — reuse the Step 2.11 disabled state.
- **Accessibility:** every tile has alt text from the brief subject; focus ring on all controls; 44px
  touch targets; the grid is fully keyboard navigable; status always colour + label.
- **Done when**: feature tests assert the page props carry no prompt/brief internals beyond the
  editable art-direction fields and no keys; a Designer sees the grid read-only; ClientReviewer gets
  403; the TSX passes `tsc`/`vp check`; visual check in light and dark mode on mobile and desktop.

### Step 16.10 — Use in post, approvals and portal ✅ Done
- `AttachGeneratedImagesToPostAction`: attaches selected variants' assets to the post (`post_assets`)
  in a transaction, re-runs `EvaluatePostChecklistAction`, writes a `PostActivityLog` entry ("Added
  2 AI images"). Never changes post status.
- Post editor media list shows a small "AI" label tag on AI-generated assets.
- ClientReviewer sees **only** attached assets on posts from "Waiting on client" onwards — never the
  set, discarded variants, the brief or cost. Covered by explicit portal/review-token tests.
- **Done when**: tests for attach (checklist re-evaluated, activity logged, status unchanged) and
  portal/review scoping regressions.

### Step 16.11 — Quota, usage and settings ✅ Done
- `organizations.ai_monthly_image_quota` (nullable int, null = unlimited) and an `ImageQuotaGuard`
  counting variants (draft + final, refine counts as one) for the calendar month; throws
  `AiImageQuotaExceededException` mapped to the friendly UI message.
- Record every vendor call in `ai_usage_logs` (Step 2.8) with `method = image.generate|image.edit`
  and the cost estimate. If 2.8 hasn't shipped yet, build that table here and let 2.8 reuse it.
- Settings → AI Usage (Owner only): images this month vs quota as a `StatStrip` (Images, Finals,
  Est. cost) with an editable quota field.
- **Done when**: guard tests (under/at/over, month rollover), usage rows on success and failure,
  Settings authz and rendered figures.

### Step 16.12 — Second adapter: GPT Image 2 for text-heavy designs (stretch) ✅ Done (HTTP-faked only; not verified live)
- `OpenAiImageProvider` implementing the same contract (`OPENAI_API_KEY`, model config).
- `ImageProviderRouter` (in `App\Services\AI\Images`) picks per request: if `textOverlay` is set and
  `config('ai.images.text_provider') = openai` is configured → OpenAI; otherwise the default provider.
  Routing decision logged with the generation id.
- **Done when**: router tests for each branch and an HTTP-faked adapter test mirroring Step 16.6.

### Step 16.13 — Housekeeping and compliance ✅ Done
- `ai-images:prune` scheduled command: deletes files for variants discarded or never selected after
  30 days (configurable), keeping any asset attached to a post. Logs counts only.
- AI disclosure: persist `is_ai_generated` through to publishing; extend `PublishAdapter` targets
  with an `aiGenerated` flag so adapters can set the platform's AI-content label where supported
  (record per-platform support in `publishing.md`). Gemini outputs carry SynthID — note it in the
  org terms copy.
- **Done when**: prune tests (attached assets untouched), and an adapter test that the flag is passed.

### Step 16.14 — Module docs ✅ Done
- Update `.junie/modules/ai.md` (Image Studio section: contract, providers, brief flow, queue,
  logging/diagnostics, quota, known gaps, changelog), `brand-brain.md` (visual references),
  `assets.md` (`AssetSource::Ai`, `storeContents()`, `variant_group_id` now used),
  `posts.md` (attach flow), `settings.md` (image quota), `publishing.md` (AI label), and the
  `modules/README.md` status table.

### Phase 16 exit criteria
- From an AI post idea, a Strategist can generate 4 on-brand draft images in the right format for the
  target platform in under ~30 seconds, see them fill in live, refine one in plain words, make it
  final and attach it to a draft post — all without leaving the page.
- Images visibly use the client's palette, imagery style and reference images; banned phrases and
  prohibited topics never reach the image prompt.
- No double charges on retries/double clicks; publishing latency is unaffected by image load.
- Quotas are enforced with friendly messages; usage is visible to Owners in Settings.
- ClientReviewers never see sets, briefs, costs or unattached variants. No keys, prompts or image
  bytes in props or logs. PHPStan level 7, Pint, PHPUnit, `tsc` and `vp check` are green.

---

## Phase 17 — Organization Analytics & Reporting ✅ Done

Goal: give every agency the same quality of analytics and saved reporting the super admin console
got in Phase 15 (Steps 15.4/15.5), scoped to their own organization: how clients are tracking, what
is going out, where approvals stall, who is carrying the load, what has been billed and what AI is
costing. Like 15.4, this is a read/reporting layer over tables the app already writes
(`clients`, `posts`, `post_targets`, `post_activity_logs`, `invoices`, `ai_usage_logs`, `users`).
No stored aggregates and no new domain model beyond saved reports.

Design decisions (apply to every step):
- **Share the core, don't fork it.** `GetPlatformAnalyticsSummaryAction` already does most of this
  maths across a list of org ids. Step 17.1 extracts the reusable parts (range resolution,
  bucketing, metric queries) so the platform and org surfaces can't drift. The platform console's
  output must not change. Its existing tests are the safety net.
- **Org timezone, not UTC.** The platform resolves ranges in UTC because it has no single timezone.
  An agency does: `organizations.default_timezone`. "Last 30 days" and every daily bucket follow the
  org's clock, so a post published at 23:30 UTC lands on the right local day in Dubai.
- **`org_id` never comes from input.** It's always the authenticated user's `org_id`. Any id in the
  filters (`client_ids`, `campaign_ids`) is validated as belonging to that org. A foreign id is a
  validation error, never an empty section that confirms it exists.
- **Role scoping is per section, not per page.** Every staff role can open analytics. Sections a
  role can't see come back `null` in props and are absent from exports (same pattern as the platform
  summary's unselected sections):

  | Section | Owner | Strategist | Designer | Viewer |
  |---|---|---|---|---|
  | `clients`, `posts`, `approvals`, `team` | ✓ | ✓ | ✓ | ✓ |
  | `revenue` (incl. retainers) | ✓ | ✓ | – | – |
  | `ai` (counts) | ✓ | ✓ | ✓ | ✓ |
  | `ai.cost_estimate` | ✓ | – | – | – |

  `revenue` mirrors `InvoicePolicy::viewLedger` and AI cost mirrors the Owner-only AI Usage settings
  page. `ClientReviewer` gets a 403 on every Phase 17 route: client-facing reporting is Phase 8.
  Org-less super admins are already bounced by `org.member`. Their view is Step 17.9.
- **Money is never summed across currencies.** Same rule as 15.4: per-currency lists. The series
  is drawn in the org's `default_currency`, falling back to the most-invoiced currency in the range
  when the org invoiced nothing in its default.
- **Engagement waits on Phase 12.** The `engagement` section is always `null` until
  `PostTargetMetrics` exists. Phase 12.2 then fills it here rather than building its own page.
- **Workload, not leaderboards.** The `team` section shows throughput per person so an Owner can
  spot an overloaded designer. It is sorted by name and has no ranking, scores or "top performer"
  copy. That keeps it useful for planning without turning into surveillance.

### Step 17.1 — Extract the shared analytics core (refactor, no behaviour change) ✅ Done
- `App\Support\Analytics\AnalyticsRange` (final readonly): the preset/custom range resolution,
  fallbacks and `granularity()` lifted out of `App\Support\SuperAdmin\AnalyticsFilters`, plus a
  `timezone` property. Presets resolve against "now" in that timezone; `from`/`to` are stored as
  `CarbonImmutable` in that timezone and converted to UTC only at query time. Keeps
  `toStoredArray()` (presets stay relative, custom stays pinned) and `toArray()`.
- `App\Support\SuperAdmin\AnalyticsFilters` composes an `AnalyticsRange` (always `UTC`) and keeps
  its public API (`range`, `from`, `to`, `orgIds`, `metrics`, `includes()`, `forOrganization()`…)
  so no caller changes.
- `App\Support\Analytics\SeriesBuckets`: builds the bucket list (key + label per day/month) and
  `countByBucket()` / `sumByBucket()`. It converts every timestamp into the range's timezone
  *before* formatting the key. That's the one line that makes per-org daily charts correct.
- Lift the private query methods of `GetPlatformAnalyticsSummaryAction` into small services in
  `App\Services\Analytics`, each taking `list<int> $orgIds` and an `AnalyticsRange`:
  - `PostActivityMetrics`: transitions to a status in a window, published/failed counts, success rate.
  - `RevenueMetrics`: `totalsByCurrency()`, invoiced/collected/outstanding/run rate, primary currency.
  - `AiUsageMetrics`: generated/failed/cost from `ai_usage_logs`.

  `GetPlatformAnalyticsSummaryAction` becomes a thin composition of these. Its output is unchanged.
- **Done when**: `PlatformAnalyticsTest`, `ReportControllerTest` and `AnalyticsFiltersTest` pass
  untouched, plus new class-based unit tests: `AnalyticsRangeTest` (each preset in `Europe/London`
  across the October DST change and in `Asia/Dubai`, custom ranges, bad input → `30d` fallback) and
  `SeriesBucketsTest` (regression: an event at `2026-03-31 23:30 UTC` buckets to `2026-04-01` in
  `Asia/Dubai` and `2026-04-01` in `Europe/London` (BST), but `2026-03-31` in UTC). PHPStan level 7
  with no new ignores.

### Step 17.2 — Org analytics filters, abilities and validation ✅ Done
- `App\Support\Analytics\OrganizationAnalyticsFilters` (final readonly): an `AnalyticsRange` in the
  org's timezone, plus `clientIds`, `campaignIds`, `platforms` (`App\Enums\Platform` values) and
  `metrics` (`METRICS = ['clients', 'posts', 'approvals', 'team', 'revenue', 'ai']`). Same
  `fromArray()` / `toStoredArray()` / `toArray()` / `includes()` contract as the platform filters,
  plus `forClient(int $clientId)` for the drill-down. Built with
  `OrganizationAnalyticsFilters::fromArray($input, $organization)`, which reads the timezone and the
  org id from the model, never from `$input`.
- `OrganizationPolicy` gains `viewAnalytics` (same-org Owner/Strategist/Designer/Viewer),
  `viewRevenueAnalytics` (Owner/Strategist) and `viewAiCost` (Owner).
- `App\Support\Analytics\AnalyticsAccess::visibleMetrics(User $user, OrganizationAnalyticsFilters
  $filters): list<string>`: intersects the requested metrics with what the user may see. A Designer
  asking for `revenue` gets it silently dropped (section `null`), not a 403. That keeps saved
  reports runnable by everyone in the org (see 17.6).
- `App\Http\Requests\Analytics\OrganizationAnalyticsFilterRequest` with static
  `filterRules(string $prefix = '')` (reused by 17.6, mirroring `AnalyticsFilterRequest`) and a
  typed `filters(): OrganizationAnalyticsFilters` accessor. `client_ids.*` and `campaign_ids.*` use
  `Rule::exists(...)->where('org_id', $user->org_id)`.
- **Done when**: unit tests for the filters (timezone taken from the org, fallbacks, sanitising,
  stored form); a policy matrix test across all six roles for the three abilities; a feature test
  where a Strategist in org A passes a client id from org B and gets a validation error; and a test
  that `visibleMetrics()` strips `revenue` for a Designer and `ai.cost_estimate` for a Strategist.

### Step 17.3 — `GetOrganizationAnalyticsSummaryAction` ✅ Done
- `App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction(Organization $organization,
  OrganizationAnalyticsFilters $filters, User $viewer): array`. Built on the 17.1 services with
  `[$organization->id]` as the scope, plus org-only sections. Returns `filters`, `metrics` (the
  visible ones), each section below (or `null`), `engagement` (`null`), `primary_currency`,
  `series` and `top_clients`. Client/campaign/platform filters narrow every section that has a
  client/post dimension.
- Sections:
  - **`clients`**: total, active, paused, offboarding, new in range, and a health split
    (`green`/`amber`/`red` counts plus the red clients with their reasons) from
    `ClientHealthService`. Health is a "right now" snapshot, not range-bound, and the UI labels it
    that way.
  - **`posts`**: created, published, failed, success rate (from `PostActivityMetrics`), scheduled
    now, pipeline counts per `PostStatus` right now, published targets by platform
    (`post_targets` → `social_accounts.platform`) and the top 5 campaigns by published.
  - **`approvals`**: from `post_activity_logs` transitions. Sent to client (→ `waiting_client`),
    approved, changes requested, first-time approval rate (approved with no `changes_requested` in
    between), median and p90 hours from `waiting_client` to the client's next decision, the same
    for `internal_review` → next step, posts waiting on the client now, and the oldest one (post
    id, client, hours waiting). Phase 9's SLA can later mark these overdue without changing the
    shape.
  - **`team`**: per staff member (excluding `ClientReviewer`), posts created (`posts.created_by`),
    transitions made (`post_activity_logs.user_id`), sent to client and scheduled, sorted by name.
    Transitions with a null `user_id` (token review) or a `ClientReviewer` actor count as
    client-side decisions, reported as one separate figure.
  - **`revenue`** (gated): invoiced/collected/outstanding/30-day run rate per currency (from
    `RevenueMetrics`), overdue per currency using the same overdue rule `InvoiceLedgerService`
    already applies (reuse it, don't re-derive it), invoices issued/paid, average days to pay, and
    active retainer total per currency (`clients.retainer_amount` for active clients).
  - **`ai`**: successful/failed generations by method (captions, trends, ideas, daily brief,
    images), images this month vs `ai_monthly_image_quota`, and `cost_estimate` only when the
    viewer has `viewAiCost` (otherwise the key is `null`).
- `series`: one bucket per day (≤ ~2 months) or month in the org's timezone: `created`,
  `published`, `failed`, `sent_for_approval`, `approved`, and `invoiced` (only when revenue is
  visible, otherwise omitted).
- `top_clients`: top 10 by published, each with health, published, scheduled, waiting on client,
  and `invoiced` (only when revenue is visible).
- Performance: everything filters on a single `org_id`, so each query hits one org's rows.
  Add a migration for any missing composite indexes the queries need (check with `EXPLAIN` on
  MySQL): `post_activity_logs (post_id, to_status, created_at)`, `posts (org_id, status)`,
  `invoices (org_id, status, issue_date)`, `ai_usage_logs (org_id, created_at)`. Turnaround
  medians are computed in PHP over one org's transitions in range, which is fine at agency scale.
  Note it as a known gap if it ever isn't.
- Logs `org_id`, `user_id`, `range`, `from`, `to`, metrics and filter ids at `info`. No client
  names and no money figures.
- **Done when**: a class-based feature test with two orgs proves org B's data never appears in
  org A's summary in any section; exact totals per section against hand-built fixtures; turnaround
  median/p90 on a known set of transitions; the timezone bucketing regression from 17.1 at action
  level; revenue `null` for Designer/Viewer and `ai.cost_estimate` `null` for Strategist; a
  multi-currency org (GBP + AED) never sums across currencies; client/platform filters narrow
  correctly; and a brand-new org with no data returns zeros and empty lists, not errors.

### Step 17.4 — Analytics page (new chapter 06 in the studio book) ✅ Done
- Route `GET /analytics` → `App\Http\Controllers\Analytics\AnalyticsController@index` inside the
  `org.member` staff group (thin: Form Request → action → `Inertia::render('analytics/index')`),
  plus the `/preview` mirror per `.junie/modules/preview-mode.md`. Props: `summary`, `filters`,
  `filterOptions` (clients, campaigns, connected platforms) and `can` (`viewRevenue`, `viewAiCost`,
  `saveReport`).
- Frontend:
  - Move the reusable pieces out of `components/super-admin/` into `components/analytics/`
    (filter bar, series chart, per-currency money list, section `StatStrip`s) and re-import them in
    the super admin pages, so both surfaces share one visual language. The super admin pages
    must look the same afterwards.
  - `resources/js/pages/analytics/index.tsx`: `ChapterHero` + `PageHeader`, the filter bar (range,
    clients, campaigns, platforms, sections), then one `Panel` per visible section with a
    `StatStrip` and its chart/table. Client health uses `StatusPill` (colour + label). Failed posts
    use the destructive token + label, never Signal Orange. One hero accent per layout. Each
    section has an `EmptyState` in the Alima voice (e.g. "Nothing to chart yet. Publish your first
    post and we'll start counting.").
  - `lib/chapters.ts`: add `analytics` as **06 Analytics** ("How the studio is tracking — output,
    approvals, billing and AI.", `LineChart`, prefixes `['/analytics']`), renumber Staff to 07 and
    Settings to 08. Sidebar and ⌘K palette entries are gated on `can.viewAnalytics` from the shared
    Inertia props.
  - Dashboard (Overview): a "See full analytics" link from the publish reliability and invoice
    totals panels to `/analytics` with the matching section pre-selected.
- **Done when**: feature tests assert the Inertia props shape for Owner, Strategist, Designer and
  Viewer (Designer: `revenue` `null` and `can.viewRevenue` false), `ClientReviewer` gets 403, the
  preview mirror renders, and query-string filters round-trip into `filters`. `tsc --noEmit` and
  `vp check` are green, and you've checked the page in the browser in light and dark mode at
  desktop and phone width.

### Step 17.5 — Client analytics drill-down ✅ Done
- `GET /clients/{client}/analytics` → `AnalyticsController@client`: the same action with
  `$filters->forClient($client->id)`, authorised by `ClientPolicy::view` **and**
  `OrganizationPolicy::viewAnalytics`. The `clients` section switches to that one client's health
  and reasons, and `top_clients` is replaced by top campaigns.
- An "Analytics" tab on the client show page (next to Campaigns/Posts), plus a link from each
  `top_clients` row on the org page.
- This is the page Phase 12.2 planned (`GET clients/{client}/analytics`). Phase 12 adds engagement
  here instead of building it separately.
- **Done when**: tests for a client in another org (404 via scoped binding or 403, matching how
  `ClientController` already behaves), section gating per role, and figures that equal the org
  summary filtered to that client id.

### Step 17.6 — Saved organization reports (CRUD) ✅ Done
- `organization_reports` table/model: `org_id` (FK, `cascadeOnDelete`), `name`, `description`,
  `filters` (JSON in the `OrganizationAnalyticsFilters::toStoredArray()` shape), `created_by` (FK →
  `users`, `nullOnDelete`), `last_run_at`. Every report is visible to the whole org (small teams,
  shared views). Factory included.
- `App\Actions\Analytics\{CreateOrganizationReportAction, UpdateOrganizationReportAction,
  DeleteOrganizationReportAction, RunOrganizationReportAction}`. `RunOrganizationReportAction` just
  rebuilds the filters and calls `GetOrganizationAnalyticsSummaryAction` with the **current
  viewer**, then stamps `last_run_at`. So a report an Owner saved with revenue, run by a Viewer,
  shows revenue as `null`. Permissions are applied when the report runs, not when it's saved.
- `OrganizationReportPolicy`: `viewAny`/`view` = same-org analytics viewers; `create` =
  Owner/Strategist; `update`/`delete` = the creator or an Owner.
- `App\Http\Controllers\Analytics\ReportController` (`Route::resource('analytics/reports')`, names
  `analytics.reports.*`) + `SaveOrganizationReportRequest` (reusing
  `OrganizationAnalyticsFilterRequest::filterRules('filters.')`). Pages
  `resources/js/pages/analytics/reports/{index,edit,show}.tsx` reusing the 17.4 components, and a
  "Save as report" button on the analytics page that pre-fills the builder (same as 15.5).
- **Done when**: CRUD tests; running a report gives the same output as calling the action directly
  with the same filters and viewer; relative presets stay relative and custom ranges stay pinned in
  storage; a report from org A is 404/403 for org B; a Designer can view but not create or edit;
  a Viewer running an Owner's revenue report gets `revenue` `null`; and a stored client id that was
  later deleted is dropped quietly, not a crash.

### Step 17.7 — Exports: CSV and branded PDF ✅ Done
- Extract the row-flattening in `App\Support\SuperAdmin\PlatformAnalyticsCsv` into a shared
  `App\Support\Analytics\AnalyticsCsv` (sections → rows, per-currency money rows, series, top
  list). `PlatformAnalyticsCsv` and a new `OrganizationAnalyticsCsv` become thin mappers.
  `GET /analytics/export` (current filters) and `GET /analytics/reports/{report}/export`, streamed
  like `ReportController::export` today.
- `App\Actions\Analytics\GenerateOrganizationReportPdfAction`: DomPDF, reusing the Alima branding
  partials from the invoice PDF (Step 7.5). Cover with org name, report name and range, one page
  per visible section, footer "Marketing brighter together.". `GET
  /analytics/reports/{report}/pdf`. This is an internal studio review document. The client-facing
  version is Phase 8.
- Exports only ever contain the sections the requesting user can see (they go through the same
  action with the same viewer), and the filename is `Str::slug(name)-Y-m-d`.
- **Done when**: CSV content tests per section, a test that a Designer's export has no revenue rows,
  a PDF response test (content type, filename, generated without errors), and the super admin CSV
  export output byte-for-byte unchanged after the extraction.

### Step 17.8 — Scheduled report emails (stretch) ✅ Done
- `organization_reports` gains `schedule` (`null|weekly|monthly`), `recipient_ids` (JSON list of
  same-org staff user ids) and `last_sent_at`. Set from the report builder (Owner/Strategist only).
- `analytics:send-scheduled-reports` command, scheduled daily (same pattern as
  `GenerateDailyBriefsCommand`). Skips suspended orgs, as daily briefs and reminders already do.
  Sends at most once per period per report (`last_sent_at`), and runs the report **per recipient**
  so each email respects that person's role. `App\Mail\OrganizationReportMail` (Resend): headline
  figures in the Alima voice, with the CSV attached and the PDF too if 17.7 shipped.
- Logs `org_id`, `report_id` and recipient count. No email addresses and no figures.
- **Done when**: tests with a faked mailer and a frozen clock confirm one send per period, nothing
  for suspended orgs, a recipient removed from the org is skipped, and a Designer recipient gets
  no revenue in the email body or attachment.

### Step 17.9 — Super admin alignment ✅ Done
- `GET /super-admin/organizations/{organization}/analytics` switches from the platform action scoped
  to one org to `GetOrganizationAnalyticsSummaryAction` with every section visible. Super admins
  then see exactly what the customer sees (in the org's own timezone), which is what support and
  sales conversations need. The platform-wide analytics page and saved platform reports stay
  on `GetPlatformAnalyticsSummaryAction`.
- **Done when**: a test proves the super admin drill-down figures equal the org Owner's
  `/analytics` figures for the same range, and the existing super admin access tests still pass.

### Step 17.10 — Module docs ✅ Done
- New `.junie/modules/analytics.md` following the standard template (purpose, models —
  `organization_reports` —, the shared `App\Support\Analytics` / `App\Services\Analytics` core,
  actions, policies and the role/section matrix, routes, business rules — timezone, per-currency
  money, run-time permissions, workload-not-leaderboard —, tests, known gaps — engagement pending
  Phase 12, PHP-side medians —, changelog). Phase 12.3 later extends this file instead of creating
  it. Add its row to `.junie/modules/README.md`.
- Update `super-admin.md` (shared core extracted, drill-down now uses the org action),
  `organizations-users.md` (new `OrganizationPolicy` abilities), `clients.md` (Analytics tab),
  `home.md` (Overview links to analytics) and `preview-mode.md` (`/preview/analytics` mirror), each
  with a changelog entry. Update the chapter list in `.junie/brand-guidelines.md` and the project
  context doc if they list chapters.

### Phase 17 exit criteria
- An Owner opens **06 Analytics**, picks "Last 90 days" and sees client health, output, approval
  turnaround, team workload, billing per currency and AI usage, all in the org's own timezone.
  They can narrow it to one client, save it as a report, re-run it next month and export it as CSV
  or PDF.
- A Designer sees the same page without revenue or AI cost, in the UI and in every export. A
  `ClientReviewer` can't reach any of it.
- No figure from one organization ever appears in another's analytics, reports or exports.
- The super admin console's platform analytics and reports return exactly what they did before
  Phase 17. Its per-org drill-down matches what the org's Owner sees.
- PHPStan level 7, Pint, PHPUnit, `tsc` and `vp check` are green.
- Implemented 2026-10-18 — see `.junie/modules/analytics.md`. Additions beyond the plan: an
  `AnalyticsScope` value object carries the client/campaign/platform narrowing into the shared
  services; `ApprovalMetrics`/`TeamWorkloadMetrics` services; current-view CSV/PDF export from the
  page and client drill-down; retainers as a monthly equivalent; Step 17.8's columns created with the
  table; note rows (`from_status = to_status`) excluded from every transition count (platform too);
  SSR hydration fix in `formatCurrencyCompact`. Browser check done for light mode at desktop width;
  dark mode and phone width still to eyeball manually.

---

## Phase 18 — Instagram Grid Planner ✅ Done

Goal: let the team plan how a client's Instagram profile grid will look before anything goes out,
preview it as the client's followers will see it, get it signed off internally and by the client,
then turn each tile into a real `Post` and schedule the set in the right order so the profile ends
up looking exactly like the plan. It works like the post editor (Step 0.10 and the 2026-10-19
composer) — workflow panel, readiness checklist, sticky preview rail, comments and activity — but the
thing being edited is the grid (tiles, order, media, framing), not captions and hashtags.

Platform facts this phase is built on (checked 2026-10-20 — re-verify at build time and record in
`grid-planner.md`):
- The profile grid shows **3:4 portrait tiles** (since early 2025), three per row. A 3:4 upload
  (1080×1440) shows in full; a 4:5 feed post is centre-cropped top and bottom on the grid; a Reel shows
  its cover cropped from 9:16 to 3:4.
- The grid is newest-first. The last post published sits top-left. **Pinned posts** (up to 3) sit in
  front of everything and push the grid along.
- Instagram added in-app grid reordering in June 2026 (long-press → "Reorder grid"; pinned posts and
  Reels stay fixed). It's not in the publishing API, so we still rely on publish order. In-app
  reordering is only the manual fix when something goes out of order. Say that in the UI copy.

Design decisions (apply to every step):
- **The grid approves the look; the post approves the words.** A grid plan's own approval covers
  tiles, media, framing and order. Each tile becomes an ordinary `Post` and goes through the normal
  post workflow for its caption, hashtags and schedule. No second state machine in posts, and no
  shortcut around `PostStatusTransitionMap` or the scheduling checklist.
- **Plan status is stored; production progress is derived.** `GridPlanStatus` only covers the
  approval stages. "4 of 9 posted · 3 scheduled · 2 live" is computed from the linked posts, never
  stored, so it can't drift.
- **One Instagram account per plan.** A plan belongs to a client and one of that client's Instagram
  `SocialAccount`s (and optionally a campaign). Other channels can still be added to a tile's post in
  the post editor afterwards; the grid only cares about Instagram.
- **Position 1 is top-left.** Tiles are stored in display order (`position` 1…n, 1 = newest). Publish
  order is the reverse. Every rule (rows, split alignment, schedule order) is worked out from that one
  ordering in one service, mirrored in TypeScript — same pattern as `CropBox` / `cropBox()`.
- **The preview shows what Instagram will show.** Grid thumbnails use the same framing maths the
  publisher uses (`PublishImageCropper` + `CropBox`), then the 3:4 grid crop on top. No separate
  "grid crop" the API can't honour.
- **AI suggests, never arranges or schedules on its own.** Any AI help (Step 18.12) produces
  suggestions a human applies.

### Step 18.1 — Data model, enums and policy ✅ Done
- `App\Enums\GridPlanStatus`: `draft`, `internal_review`, `waiting_client`, `changes_requested`,
  `approved`, `archived`, with `label()` and `clientVisible()` (`waiting_client` onwards, mirroring
  `PostStatus::clientVisible()`). `App\Enums\GridTileKind`: `image`, `carousel`, `reel`, `placeholder`
  (a reserved slot with a colour and a note, no media yet).
- `grid_plans`: `org_id`, `client_id`, `social_account_id` (must be `Platform::Instagram`, same
  client), `campaign_id` nullable, `name`, `status`, `review_message` nullable (the note for the
  client, like `posts.review_message`), `pinned_count` (0–3, default 0), `include_live_feed` (bool,
  default true), `version` (unsigned int, bumped on every layout change, used for stale-write checks),
  `approved_at`/`approved_by` nullable, `approved_layout_hash` nullable, `created_by`, timestamps,
  soft deletes. Index `(client_id, status)`.
- `grid_tiles`: `grid_plan_id`, `position`, `kind`, `format` (`MediaRatio`, default `3:4` for images
  and carousels, `9:16` for Reels), `cover_asset_id` nullable (Reel cover), `placeholder_colour`
  nullable (a brand palette token name, never a hex from input), `team_note` nullable (internal only),
  `caption_idea` nullable (seeds the post's master caption on conversion), `planned_local_date`/
  `planned_local_time` nullable, `split_group_id` nullable uuid, `post_id` nullable unique FK
  (`nullOnDelete`), timestamps. Unique `(grid_plan_id, position)`, enforced in a transaction on
  reorder, not left to deferred constraints (SQLite can't defer).
- `grid_tile_assets` pivot: `grid_tile_id`, `asset_id`, `sort_order`, `crop_x`/`crop_y`/`crop_zoom`
  (same ranges and defaults as `post_assets`). Carousels hold 2–10 items, from the registry's
  Instagram carousel `FormatSpec`, not hard-coded.
- `grid_plan_activity_logs` (`from_status`, `to_status`, `user_id` nullable, `note`,
  `review_token_id` nullable) and `grid_comments` (`grid_plan_id`, `grid_tile_id` nullable for
  tile-pinned comments, `user_id` nullable, `body`, `internal_only`). Same shapes as their post
  counterparts so the discussion components can be reused.
- `review_tokens.grid_plan_id` nullable FK. A token carries `post_id` **or** `grid_plan_id`, never
  both (checked in `CreateReviewTokenAction`).
- Models with typed `@property` docblocks and enum casts. `Post::gridTile()` (`HasOne`) so the
  editor knows a post came from a grid.
- `GridPlanPolicy`: `viewAny`/`view` org-scoped staff, plus `ClientReviewer` for their own client
  and only when `status->clientVisible()`. `create`/`update`/`transition`: Owner/Strategist/Designer.
  `delete`: Owner/Strategist. `update` is false while `waiting_client` (same lock as a post that's
  out for review). `Viewer`: read only.
- Status tones in `lib/status.ts` reuse the post tones for the same-named statuses, so a grid and a
  post in "Waiting on client" look identical. Always rendered through `StatusPill`, never Signal
  Orange.
- **Done when**: migration/factory tests, a policy matrix across all six roles (including a
  `ClientReviewer` denied on a `draft` grid of their own client and on any grid of another client),
  the Instagram-only and same-client account rule rejected at validation, the token
  `post_id`/`grid_plan_id` exclusivity, and PHPStan level 7 with no ignores.

### Step 18.2 — Grid layout core (ordering, rows, splits, framing, checklist) ✅ Done
- `App\Services\Grids\GridLayoutService` (pure, no I/O): given a plan's tiles, `pinned_count` and
  the "newer than this plan" posts (Step 18.11), returns each tile's display slot, row and column,
  its publish sequence number (reverse of position), and which row each split group lands on.
- `App\Support\Grids\GridTileFrame`: the grid thumbnail for a tile. It takes the tile's first image
  framed by `CropBox` at the tile's `format`, then centre-crops that frame to 3:4. Returns the
  rectangle plus `croppedOnGrid: bool` and the share of the image hidden. Mirrored in
  `resources/js/lib/grid-layout.ts` (`gridSlots()`, `gridTileFrame()`), with a shared fixture JSON
  both test suites read so PHP and TS can't drift.
- `App\Actions\Grids\EvaluateGridChecklistAction` → `{items: [{key, label, passed, severity,
  detail, tile_ids}], passed}`. Hard items: `tiles` (at least one), `media` (no placeholders left,
  carousels within item bounds, every Reel has a video), `formats` (each tile's format is one
  Instagram offers), `split_alignment` (every split group fills whole rows once pinned posts and
  newer tiles are counted — "This split won't line up. Add or remove one tile above it."),
  `account` (the Instagram account is still connected). Soft items: `grid_crop` (4:5 tiles that lose
  part of the image on the grid), `dates` (planned dates in publish order, none in the past),
  `outside_posts` (other posts on this account scheduled between grid dates will shift the grid).
  Runs on every save and is snapshotted as `checklist_snapshot` (json) on `grid_plans`.
- `layoutHash()`: SHA-256 of the canonical order + asset ids + framing + formats. Stored as
  `approved_layout_hash` on approval and compared later to spot drift (Step 18.11).
- **Done when**: unit tests for slot/row maths with 0–3 pinned posts, publish sequence reversal,
  split alignment (aligned, off by one, off by two, two split groups stacked), `GridTileFrame` for
  3:4 / 4:5 / 1:1 / 1.91:1 / 9:16 inputs at zoom 1 and 2, the PHP/TS shared fixture passing on both
  sides, every checklist item passing and failing on its own, and `layoutHash()` stable across reloads
  but changing when one crop moves.

### Step 18.3 — Live profile feed source ✅ Done
- `App\Contracts\ProfileFeedSource`: `recent(SocialAccount $account, int $limit = 30):
  ProfileFeed` (readonly DTO: `username`, `profilePictureUrl` nullable, `items` — `id`, `mediaType`,
  `thumbnailUrl`, `permalink`, `publishedAt` — and `source`: `meta|app|none`).
- `MetaGraphProfileFeedSource`: `GET /{ig-user-id}?fields=username,profile_picture_url` and
  `GET /{ig-user-id}/media?fields=id,media_type,media_url,thumbnail_url,permalink,timestamp` using
  the Step 1.9 `meta_access_token`. Same host check, timeouts and jittered retry as
  `MetaGraphMusicProvider`. Never logs the token or the media URLs. Cached 15 minutes per account
  (`grid-feed:{account_id}`), with a "Refresh" that busts it (rate-limited per account).
- `PublishedPostsProfileFeedSource` fallback: the app's own published Instagram posts for that
  account (first asset as the thumbnail), used when the account hasn't done the Facebook Login
  connect. The UI says so plainly: "Showing posts sent from Alima. Connect Instagram to see your
  whole profile."
- `CompositeProfileFeedSource` (bound in `AppServiceProvider`): Meta when connected, else app,
  never throws to the page. A Graph failure logs a `warning` with the account id and falls back.
- Whether the Graph API exposes pinned state: check at build time. If it doesn't, `pinned_count`
  stays a manual setting on the plan, and the preview labels pinned tiles "Pinned".
- **Done when**: `Http::fake()` tests for the request shape, mapping (IMAGE / CAROUSEL_ALBUM / VIDEO
  thumbnail selection), cache hit and bust, fallback on 4xx/5xx/timeout and when not connected, a
  log-assertion test that no token is ever logged, and a props test that no token reaches the page.

### Step 18.4 — Plan and tile actions, routes ✅ Done
- Actions in `App\Actions\Grids` (each in `DB::transaction()`, each bumps `version`, re-runs the
  checklist and logs `grid_plan_id`/`org_id`/`user_id`):
  `CreateGridPlanAction`, `UpdateGridPlanAction` (name, campaign, pinned count, review message),
  `AddGridTilesAction` (from assets, uploads, placeholders, or Image Studio variants. It inserts at a
  position and shifts the rest), `UpdateGridTileAction` (kind, format, assets and order, framing,
  cover, note, caption idea, planned date), `ReorderGridTilesAction` (the full ordered id list plus
  `expected_version`; a stale version → 409 "Someone else changed this grid. Refresh to see it."),
  `RemoveGridTileAction`, `DuplicateGridPlanAction`, `ArchiveGridPlanAction`.
- `SplitAssetIntoGridTilesAction`: slices one image into 3 / 6 / 9 tiles (1×3, 2×3, 3×3 at 3:4 each)
  using `ImageNormalizer` + `AssetStorage::storeContents()`. It creates derived `Asset`s grouped by
  `split_group_id` and points back to the source asset, so the original is never changed. Rejects
  images too small for the chosen split at 1080px per tile, with a friendly message.
- Editing a plan that is `approved` or `changes_requested` moves it back to `draft` (with an
  activity log row "Reopened by edit"). The UI confirms first. Same rule as campaign briefs.
- Thin controllers: `GridPlanController` (`index` under `clients/{client}/grids`, `store`, `edit`
  as the planner page, `update`, `destroy`), `GridTileController` (`store`, `update`, `destroy`,
  `reorder`, `split`), with Form Requests that check every asset id belongs to the plan's client and
  every Image Studio variant is `selected`. GET routes mirrored under `/preview`; the preview
  middleware 403s every write.
- **Done when**: feature tests per action (happy path, cross-client asset rejected, carousel bounds,
  insert-shift ordering, stale reorder → 409 with nothing changed, reopen-on-edit, split producing N
  correctly sized derived assets with the source untouched, too-small source rejected), a Designer
  can edit, a Viewer can't, and preview mode can't write.

### Step 18.5 — Grid planner page (the editor) ✅ Done
Lives at `GET /grids/{grid}/edit`, reached from a new **Grid** tab on the client page (shown when
the client has an Instagram account) and from the campaign workspace when the plan has a campaign.
Follows `.junie/brand-guidelines.md` and the post editor's layout so the two feel like siblings.

- **Header:** `PageHeader` with DM Mono eyebrow `GRID PLANNER`, the plan name, account handle chip,
  `StatusPill` and campaign chip. Headline on the index page: "Plan the grid."
- **Workflow panel:** reuse the `post-workflow.tsx` pattern with grid stages (Draft → Internal
  review → Client review → Approved → In production → Live). "In production" and "Live" come from
  the derived progress. The primary next step is an Ink button, backward moves sit under "More",
  every move confirms with an optional note.
- **Left: the board.** A 3-column board of 3:4 tiles in display order with row guides. Drag to
  reorder, using the drag-and-drop library the campaign Kanban board already uses, with keyboard
  reordering (space to lift, arrows to move, space to drop) and a live announcement for screen
  readers. Split groups move as one block. Each tile shows its sequence ("Goes out 3rd"), kind icon,
  a carousel count, a Reel badge, and a `StatusPill` for its post once it has one.
- **Add tiles:** an "Add" tile at the top-left slot opens: Upload, Browse library
  (`AssetLibraryDialog`), From Image Studio (selected variants for this client), Split an image,
  Placeholder.
- **Tile inspector (right, replaces the board's empty state when a tile is selected):** kind, media
  list in carousel order (reorder, remove, alt text), Reel cover picker, format (3:4 marked
  "Fills the grid"), focus point and zoom with a live grid-crop overlay showing what the grid hides,
  team note (marked "Only your team sees this"), caption idea, planned date and time in the
  account's timezone.
- **Sticky rail:** `ReadinessChecklist` fed by the grid checklist (items jump to the tile or
  setting they're about) and the preview (Step 18.6).
- **Discussion:** `PostDiscussion` reused for grid comments and activity. Tile-pinned comments show
  a small "Tile 4" chip and highlight that tile on hover.
- **Unsaved changes:** same sticky save bar, ⌘/Ctrl+S, and `use-unsaved-changes-guard` as the post
  editor. Reorders save straight away (optimistic, rolled back with a toast on 409).
- **States:** `EmptyState` — "No tiles yet." / "Add a few images and we'll show you how the profile
  will look." / "Add tiles". Read-only for Viewers and while `waiting_client`, with a lock notice.
- **Done when**: Inertia props tests per role (no token, no `team_note` for anyone but staff), the
  page renders for a plan with every tile kind, keyboard reorder works, `tsc`/`vp check` green, and
  a browser check in light and dark mode at desktop and phone width.

### Step 18.6 — Preview functions ✅ Done
One `GridPreview` component (`components/grids/grid-preview.tsx`), reused by the planner, the
review link and the portal, so everyone sees the same thing.

- **Phone frame:** profile header (picture, username, post count from the feed source), the
  Posts / Reels / Tagged tabs drawn as static chrome, then the 3-column 3:4 grid with Instagram's
  thin gutters. Light and dark Instagram themes, separate from the app theme.
- **Plan + live feed:** planned tiles first (after any pinned posts), then the live feed underneath,
  slightly dimmed, with a quiet divider "Already on the profile". Toggle "Plan only".
- **As of date:** a scrubber that shows the profile as it will look on a given day, with tiles
  dropping in in publish order. It's the clearest way to show a client how the grid builds up and
  where it looks odd halfway through.
- **Grid view vs post view:** tapping a tile opens a lightbox with the full post preview
  (`SocialPostPreview` Instagram layout: carousel swipe, Reel frame, the caption idea or the linked
  post's published caption). Arrow keys move between tiles.
- **Crop truth:** a toggle that outlines the part of each image the grid hides, for 4:5 tiles and
  Reel covers.
- **Split seams:** a toggle that draws the seams of split groups, to check that edges line up.
- **Outside posts:** other scheduled posts on the same account that fall between grid dates appear
  in their real slot, marked "Not in this plan" (Step 18.11).
- **Device sizes:** small / large phone widths. Tiles use the same `GridTileFrame` maths at every
  size.
- Performance: thumbnails are served as normalised WebP previews (Step 16.2's normaliser), lazy
  loaded, and the live feed is capped at 30 items.
- **Done when**: component checks for each toggle, the as-of scrubber hiding exactly the tiles
  scheduled after the chosen date, the PHP/TS frame fixture rendering the same crops as the server,
  focus management in the lightbox, and `prefers-reduced-motion` respected.

### Step 18.7 — Workflow and internal review ✅ Done
- `App\Actions\Grids\GridPlanStatusTransitionMap` (one explicit `MAP`, like posts):
  `draft → {internal_review, archived}`, `internal_review → {draft, waiting_client, approved}`,
  `waiting_client → {approved, changes_requested}`, `changes_requested → {draft, internal_review}`,
  `approved → {draft, archived}`, `archived → draft`. Approval mode follows the client's
  `default_approval_mode`: with `client_required`, `internal_review → approved` is blocked, and an
  Owner override needs a reason, as `TransitionPostStatusAction::overrideApproval()` does.
- `TransitionGridPlanStatusAction`: checks the map, requires a passing hard checklist before
  `waiting_client` or `approved`, writes the activity log, stamps `approved_at`/`approved_by` and
  `approved_layout_hash` on approval, and notifies through the existing notifications module
  (sent for review, approved, changes requested; `@`-mentions in grid comments via
  `CommentMentionParser`). `CreateGridCommentAction` forces `internal_only = false` for
  `ClientReviewer`s.
- `POST /grids/{grid}/transition` and `POST /grids/{grid}/comments` with Form Requests mirroring
  `TransitionPostRequest`/`StorePostCommentRequest`.
- **Done when**: a map test proving every status has an entry, valid and rejected transitions over
  HTTP, the checklist gate, the client-required block plus an Owner override with a reason, the
  approval stamp and hash, notifications faked and asserted, and internal-only comments never
  stored for a reviewer.

### Step 18.8 — Client approval (review link and portal) ✅ Done
- On `waiting_client`, `SendClientGridReviewRequestedEmailAction` mints a grid-scoped `ReviewToken`
  and sends `ClientGridReviewRequestedMail` (Resend, Alima voice: "Your new grid is ready to look
  at.") to `Client::approval_email`. If it's missing, the send is skipped with a warning, as for
  posts. Resend from the activity log like posts.
- `ReviewController::show` renders `review/grid` when the token carries a `grid_plan_id`: the
  `GridPreview` (with the live feed), the review message, and tile lightboxes. Approve / Request
  changes for the whole grid; request changes can flag specific tiles, which become tile-pinned
  comments. The token is checked exactly like post tokens (hash, expiry, revoked, client scope → 404).
- Portal: `GET /portal/grids`, `GET /portal/grids/{grid}`, `POST …/decide`, `POST …/comments`,
  filtered by `GridPlanStatus::clientVisible()` and the contact's own `client_id` in
  `EnsureClientPortalAccess`. A "Grids" entry in the portal nav, shown only when the client has at
  least one visible grid. Portal dashboard: a "Grid waiting on you" row.
- Payloads carry **no** `team_note`, internal comments, Image Studio sets or costs, other clients'
  data, or any billing field. A stale-decision check (layout `version` seen vs current) stops a
  client approving a grid that changed while they were looking: "This grid changed while you were
  looking. Here's the latest."
- **Done when**: tests for the email (sent, skipped without an address), a valid token approving
  and requesting changes with flagged tiles, invalid/revoked/expired/cross-client tokens → 404, a
  draft grid invisible in the portal, the §12-style isolation test (no `team_note`, no internal
  comments, no billing fields in props), and the stale-decision check.

### Step 18.9 — Turn tiles into posts ✅ Done
- `CreatePostsFromGridTilesAction` (approved plans only; all tiles or a selection): for each tile
  without a post, `CreatePostAction` with the plan's client and campaign, one Instagram `PostTarget`
  on the plan's account (local date/time from the tile's planned date, UTC derived from the
  account's timezone as usual), `master_caption` from `caption_idea`, the tile's assets in carousel
  order with their framing copied to `post_assets`, `media_format.instagram` from the tile format,
  the target `format` (feed / carousel / reel) and the Reel cover. Sets `grid_tiles.post_id`. One
  transaction per batch; a tile that fails validation is reported, not half-created.
- New posts start in `draft` with the client's approval mode: the client approved the look, the
  words still need sign-off. The post editor shows a "From grid: {name} · Tile 4 · Goes out 3rd"
  chip linking back, and the review link and portal show "Look approved in {grid name}" so the
  client isn't asked about the image twice.
- On the planner, each tile gets "Open post" once linked. Deleting a post frees its tile
  (`nullOnDelete`) and the tile shows "No post yet" again.
- Bulk-move the new posts (submit / send to client) so a full grid's captions can go to the client
  as one batch review. Reuse `BulkUpdateCampaignPostsAction`'s logic; if it's tied to a campaign,
  extract the post-list part into a shared action first rather than copying it, since a grid may
  have no campaign.
- **Done when**: tests for N tiles → N posts with correct assets, order, framing, format, cover and
  per-target time; idempotency (running twice creates nothing new); a non-approved plan rejected; a
  partially invalid selection reporting the bad tile and creating the rest; and the post editor/
  portal props carrying the grid chip.

### Step 18.10 — Schedule the sequence ✅ Done
- `PlanGridScheduleAction` (preview + apply, like `ShiftCampaignAction`): pick a start date, a
  cadence (every day / chosen weekdays / every N days) and a time. It assigns planned dates to tiles
  in **publish order** (last position first) and writes them to each linked post's Instagram
  target through `UpdatePostAction`, so recall and re-submit of vendor-held channels keeps working.
  `preview()` writes nothing.
- `GridSequenceValidator`: publish times must strictly increase in publish order with a minimum gap
  (config `grids.min_gap_minutes`, default 10) so the vendor can't swap two close posts. A move that
  breaks order — the calendar's drag-and-drop or the post editor — is still allowed, but the post
  and the plan get a warning ("This puts tile 4 before tile 5. The grid will be out of order.")
  and the grid checklist fails `dates`.
- "Schedule approved posts": transitions every linked post that is `approved` to `scheduled` with
  `TransitionPostStatusAction`, one post at a time, each through its own checklist. Posts that
  aren't approved yet are listed, not skipped silently. Never schedules anything on its own.
- **Done when**: tests for date assignment in reverse order across a DST change in the account's
  timezone, preview writing nothing, the min-gap rule, an out-of-order calendar move producing the
  warning without blocking, the bulk schedule moving only approved posts with passing checklists,
  and vendor-held posts recalled and re-sent when the sequence is re-planned.

### Step 18.11 — Keep the grid true after scheduling ✅ Done
- **Outside posts:** `GridLayoutService` takes every other Instagram feed post (not Stories) on the
  same account scheduled or published between the plan's first and last dates and places it in its
  real slot. The checklist's soft `outside_posts` item and the preview's "Not in this plan" tiles
  come from here. The planner offers "Add to plan" to pull one in as a tile.
- **Drift:** `GridDriftService` compares each linked post's current media, framing and format with
  its tile, and the plan's layout with `approved_layout_hash`. A drifted tile shows "Changed since
  the client approved the grid" in the planner and as a soft `grid_match` item in the post's
  checklist. The fix is either "Update the tile from the post" (which reopens the plan to draft) or
  "Put the post back".
- **Order at risk:** when a grid post fails, is recalled or moves back out of `scheduled` while a
  later tile is still scheduled, raise a "Grid order at risk" row on Home (needs attention) and
  notify the plan's creator. The row offers "Hold the rest" (moves the later scheduled posts back to
  `approved` through the normal transition action, recall included) and points to Instagram's
  in-app "Reorder grid" if something has already gone out. Only a person clicks it.
- Calendar: grid posts get a small grid chip and a "Show this grid" filter.
- **Done when**: tests for outside posts landing in the right slot, drift detection on media/crop/
  format changes and on a reorder after approval, the Home row appearing on a failed grid post with
  later tiles scheduled (and not when the failed tile was the last one), "Hold the rest" moving only
  later tiles, and the calendar filter.

### Step 18.12 — AI assist (stretch) ✅ Done
- "Suggest captions for this grid": runs the existing caption suggestion per tile from the tile's
  image description and the client's Brand Brain, written into `caption_idea` only when someone
  clicks "Use". Goes through `AiGuardrailService` and counts in `ai_usage_logs`.
- "Check the balance": a DeepSeek pass over tile descriptions + dominant colours (worked out
  locally with the image normaliser, no vision call) against Brand Brain's palette and pillars,
  returning short notes ("Three product shots in a row on row 2. Try moving tile 5 up."). Shown as
  suggestions; the team reorders by hand.
- "Fill this slot" on a placeholder opens Image Studio prefilled at 3:4 with the plan's palette.
- Off when AI is disabled (reuse the Step 2.11 disabled state). No prompt, brief or key in props or
  logs.
- **Done when**: HTTP-faked tests for each call, nothing written until "Use", guardrail regression,
  usage rows, and the disabled state.

### Step 18.13 — Preview fixture and housekeeping ✅ Done
- `PreviewSeeder` gets one approved grid plan (nine tiles: a 1×3 split, a carousel, a Reel, six
  linked posts) so `/preview` shows the feature off. Write-blocked as usual.
- `DemoContentSeeder` gets a draft grid per demo client.
- Archived plans older than 12 months are pruned by `grids:prune` (soft-deleted, derived split
  assets kept while any post uses them). Logs counts only.
- **Done when**: the seeder is idempotent, preview GETs render, and prune tests leave linked assets
  alone.

### Step 18.14 — Module docs ✅ Done
- New `.junie/modules/grid-planner.md` on the standard template: purpose, models, the layout core
  and its TS mirror, the feed source contract, actions, policy and role scoping, routes, business
  rules (look vs words approval, position 1 = top-left, split alignment, drift, order at risk),
  the platform facts with their verification date, tests, known gaps, changelog. Add its row to
  `.junie/modules/README.md`.
- Update `posts.md` (`Post::gridTile()`, grid chip, `grid_match` check), `client-portal.md`
  (portal grids, review link grid view), `publishing.md` (sequence and min-gap rule, order-at-risk
  hold), `social-accounts.md` (feed source using the Meta token), `campaigns.md` (grids in the
  workspace), `calendar.md` (grid chip and filter), `home.md` (order-at-risk row),
  `notifications.md`, `assets.md` (split-derived assets), `ai.md` (if 18.12 ships) and
  `preview-mode.md`, each with a changelog entry.

### Phase 18 exit criteria
- A Strategist builds a nine-tile plan for a client's Instagram account (a split banner, a carousel,
  a Reel and single images), sees it in a phone-frame preview on top of the real profile, scrubs
  through how it builds up day by day, and sends it for internal review and then to the client.
- The client approves it from the email link or the portal, with tile-level comments. They never see
  team notes, internal comments, costs or anything belonging to another client.
- One click turns the approved tiles into draft posts with the right media, framing, format and
  planned times. The captions go through normal post approval, and approved posts are scheduled in
  publish order with the checklist enforced per post.
- After publishing, the live profile matches the approved plan. If a post fails or something else
  goes out in between, the team is told before the grid goes wrong, with a one-click way to hold
  the rest.
- PHPStan level 7, Pint, PHPUnit, `tsc` and `vp check` are green; `grid-planner.md` and the touched
  module docs are updated.

---

## Cross-cutting, apply throughout every phase

- **Security (spec §10)**: no API keys in the frontend bundle at any point; review tokens hashed; reviewer queries scoped by `clientId`; audit log on schedule/publish/approval events; media only via signed URLs.
- **Out of scope reminder (spec §13)**: do not build ads manager, Meta Business Suite automation, Planable sync, or white-label billing — but keep the org/workspace schema flexible enough that billing can be added later without a migration headache.
- **Adapter swap safety**: confirm periodically that changing the adapter's config name never requires migrating post UUIDs (§12) — this is a cheap regression test worth keeping in the suite permanently.
