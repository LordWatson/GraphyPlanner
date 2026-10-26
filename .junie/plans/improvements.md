# Graphy — Improvements & Enhancements Plan (Audit, 2026-10-18)

A full audit of the Graphy (Alima Planner) codebase as of `master` @ `804b40e`, after Phase 17. It
covers everything that exists today: data model, queries, publishing, workflow, calendar, CRM, Brand
Brain, assets, approvals and portal, AI, analytics, billing, team, super admin, frontend, and ops.

This file sits alongside `.junie/plans/graphy-development-steps.md`. That plan says what to **build**;
this one says what to **fix, harden, deepen, or change** in what has been built, so the product can
compete with Sprout Social, Hootsuite, Later, Planable, Agorapulse and Buffer on the things agencies
judge a tool by: reliability of publishing, speed, the approval experience, and proof of results.

How to use this file (same rules as the development plan):

- Each step is sized to hand to Junie as one unit of work: a goal, the files/models it touches, and
  a **Done when**.
- Every step follows `.junie/AGENTS.md`: thin controllers, Actions/Services, interfaces where
  swappable, `DB::transaction()` for multi-write logic, logging at boundaries (never secrets),
  PHPUnit coverage (happy path + rejections + regression test for bugs), and the matching
  `.junie/modules/*.md` updated in the same change.
- Frontend steps follow `.junie/brand-guidelines.md`.
- Step ids are prefixed `I-` (e.g. `Step I-1.3`) so they never collide with the development plan's
  `Step 1.3`. Mark finished steps `✅ Done` with an "Implemented via …" note, as the development plan
  does.
- Severity tags: **[P0]** correctness/security/data-loss, fix first · **[P1]** performance or a
  core-workflow gap that costs agencies time daily · **[P2]** competitive feature depth ·
  **[P3]** polish/stretch.

---

## Audit summary

### What is strong already

- Clean layering: Actions/Services/Contracts are used consistently, controllers are mostly thin, and
  `PublishAdapter`, `AiProvider`, `ImageProvider`, `MusicProvider` and `AssetStorage` are proper seams.
- The dual-timezone target model (`PostTarget` with independent local date/time) is correct and
  tested. Most competitors don't do this.
- An explicit post state machine (`PostStatusTransitionMap`), activity log, approvals, internal-only
  comments, mentions.
- Real webhook signature verification with timestamp skew protection, encrypted vendor keys,
  hashed review/invitation tokens.
- Broad feature surface for an early product: portal, invoicing with PDFs, AI captions/ideas/briefs,
  an AI Image Studio, org analytics with saved/scheduled reports, super admin console.

### Top findings (fix in this order)

| # | Severity | Area | Finding | Step |
|---|---|---|---|---|
| 1 | P0 | Security | `EnsurePreviewMode` calls `Auth::login()`, which **persists** the preview owner in the session. One visit to `/preview/dashboard` makes the visitor the preview owner on the real, writable routes (`/clients`, `/posts/*`), and it logs a real user out of their own account. | I-1.1 |
| 2 | P0 | Data loss | `invoices.client_id` is `cascadeOnDelete`, and `ClientController::destroy` hard-deletes. Deleting a client destroys its financial records, posts, approvals and audit trail. Nothing in the app uses `SoftDeletes`. | I-1.5 |
| 3 | P0 | Security | Client-facing invoice PDFs and all assets live on the `public` disk, with permanent unauthenticated URLs. This breaks spec §10 ("media only via signed URLs"). | I-1.6 |
| 4 | P0 | Security | The plaintext review link (raw token) is stored in `post_activity_logs.review_url` and sent to the editor. This undoes the point of hashing `ReviewToken` at rest. | I-1.6 |
| 5 | P0 | Security | `SocialAccount::meta_access_token` is encrypted but not `#[Hidden]`. Any future `->toArray()`/`Inertia::render($account)` would leak it. The Upload-Post connect callback trusts unsigned query params. | I-1.6 |
| 6 | P0 | Reliability | `PublishPostJob` has no `$tries`/`$backoff`/`$timeout`, no uniqueness, and no row lock. The vendor HTTP client has no timeout/retry. Posts can sit in `publishing` forever. One failed target fails the whole post, and there's no retry for just that target. | I-1.3 |
| 7 | P0 | Reliability | `TransitionPostStatusAction` sends the client email **inside** the DB transaction, with no row lock. Concurrent approve/request-changes (review link + portal) can race, and an email can go out for a transaction that rolls back. | I-1.4 |
| 8 | P0 | Reliability | No scheduled command uses `withoutOverlapping()`/`onOneServer()`. The 5-minute poll can overlap itself and run twice on multi-server deploys. | I-1.8 |
| 9 | P1 | Performance | MySQL indexes foreign-key columns automatically, but the composite and non-FK hot filters (e.g. `(client_id, status)`, `(org_id, connection_status)`) have no index. `post_targets.scheduled_at_utc` (used by every scheduler/upcoming query) isn't indexed either. | I-2.1 |
| 10 | P1 | Performance | Home loads **every post the org has ever made** (with targets, accounts, assets) and filters in PHP. The calendar loads every post with no date window. The client page loads every post, asset, invoice, trend and idea up front for one 2,639-line React page. | I-2.2 – I-2.4 |
| 11 | P1 | Rate limits | AI endpoints are `GET`, write to the DB, cost money per call, and have no throttle. Review/invite `POST`s, music search, exports and PDFs are unthrottled too. | I-1.7 |
| 12 | P1 | Workflow | No top-level "Posts/Content" area, no post delete/duplicate/archive UI, no per-platform caption variants, no first comment/alt text fields, no assignee or due date. | I-4 |
| 13 | P1 | Proof of value | No engagement metrics (Phase 12 not built), so analytics can't answer the question clients pay for: "did it work?" | I-10.1 |
| 14 | P2 | Ops | Queue/cache/session all run on the `database` driver. Most mail and every notification is sent synchronously. There is no error tracking, no failed-job alerting and no scheduler heartbeat. | I-16 |

### Planned-but-unbuilt phases (from the development plan)

Phases 8 (client reporting), 9 (approval SLA), 10 (bulk import), 11 (recycling), 12 (engagement
analytics), 13 (API/webhooks), 14 (white-label) and AI Steps 2.7–2.12 are still open. This file
doesn't duplicate them. Where an improvement depends on or changes one, the step says so. The
recommended order of those phases is in **Sequencing** at the end.

---

## Phase I-1 — Critical correctness & security fixes [P0]

Goal: close every data-loss, tenant-leak, and secret-exposure risk, and make publishing deterministic
under retries and concurrency. Nothing in later phases should start before this phase is green.

### Step I-1.1 — Preview mode must never create a real session [P0] ✅ Done
- `App\Http\Middleware\EnsurePreviewMode` currently calls `Auth::login($previewUser)`, which writes
  the preview owner into the session. Replace it with a request-scoped identity:
  `Auth::setUser($previewUser)` (or `Auth::onceUsingId()`), so nothing is persisted.
- Stop a logged-in real user from being swapped out: if `$request->user()` exists and isn't the
  preview user, keep their session untouched and resolve the preview user only for this request.
- Add a global guard (e.g. in `EnsureUserHasOrganization` or a new `RejectPreviewUserOutsidePreview`
  middleware on the `web` group): the preview user may never be authenticated on a non-`/preview/*`
  URL. Log it at `warning` and return a 403 if they are.
- Fix the first-load crash (`auth.user: null` on the first `/preview/*` request, documented in
  `preview-mode.md`): run `EnsurePreviewMode` before `HandleInertiaRequests` resolves shared props
  (add it to the middleware priority list), or resolve the user lazily in `share()`.
- Make reused pages preview-aware: add a `preview` route-name helper on the frontend (prefix
  `preview.` when the `preview` shared prop is true) so links and forms stay inside the mirror, and
  disable or hide every write control when `preview` is true.
- **Done when**: a regression test visits `/preview/dashboard`, then requests `/clients` and gets
  redirected to login (not the preview org). A logged-in Owner who visits `/preview/dashboard` is
  still themselves on `/dashboard` afterwards. The first preview request renders with a non-null
  `auth.user`.
- Implemented via `EnsurePreviewMode` swapping the identity per request (`Auth::guard()->setUser()`
  + restore, never `Auth::login()`), the new `RejectPreviewUserOutsidePreview` web middleware
  (signs out + 403s the preview owner anywhere outside `/preview/*`), and the frontend
  `usePreviewGuard` (`resources/js/lib/preview.ts`) that re-routes mirrored GET visits to
  `/preview/*` and cancels writes with a toast. The first-load `auth.user: null` crash was already
  fixed on 2026-10-16 (closures in `HandleInertiaRequests::share()`). Write controls are blocked
  by the guard but not yet visually hidden (`useIsPreview()` is ready). Tests:
  `PreviewModeTest`. See `.junie/modules/preview-mode.md`.

### Step I-1.2 — Stale publish jobs and safe rescheduling of scheduled posts [P0] ✅ Done
- Today a delayed `PublishPostJob` stays queued when a post is moved `scheduled → approved/draft`
  and back. The old job fires at the old time, flips the post to `publishing` early, and sends it to
  the vendor. Rescheduling a `scheduled` post from the calendar is blocked entirely to avoid this
  (see `PostPolicy::reschedule` and `calendar.md`).
- Add `posts.publish_dispatch_id` (uuid, nullable). `TransitionPostStatusAction::dispatchPublishJob()`
  writes a new uuid and passes it to the job. `PublishPostJob::handle()` no-ops (logs `info`) when
  its id no longer matches the post's. Leaving `scheduled` clears it.
- Once stale jobs are harmless, allow `ReschedulePostAction` and `UpdatePostAction` on `scheduled`
  posts. Both re-dispatch with a fresh id inside the same transaction (after commit), and call
  `PublishAdapter::cancel()` first if the vendor already holds the job (see I-3.6).
- Decide and record in `publishing.md`: either keep delayed local jobs, or **hand the post to
  Upload-Post as soon as it's scheduled** (it already accepts `scheduled_date`). Handing off early
  surfaces vendor rejections (bad media, expired token) days ahead instead of at publish time, which
  is what agencies expect. The recommendation is to hand off early, with a re-sync on edit.
- **Done when**: tests cover (a) unschedule → reschedule leaves exactly one effective publish,
  (b) dragging a `scheduled` post re-dispatches and the stale job no-ops, (c) a moved-earlier post
  publishes at the new time.
- Implemented via `posts.publish_dispatch_id` (written on every move into `scheduled`, cleared on
  the way out; `PublishPostJob` no-ops on a mismatch), `ReschedulePostAction::redispatchIfScheduled()`
  (also used by `UpdatePostAction`), and `PostPolicy::reschedule` allowing `scheduled` posts.
  **Decision recorded in `publishing.md`:** delayed local jobs are kept for now — handing off to
  Upload-Post early needs a working `PublishAdapter::cancel()`/re-sync (I-3.6). Content edits on
  `scheduled` posts stay policy-locked (they'd bypass the client's approval). Tests:
  `PublishPipelineTest` (a)–(c), `ReschedulePostTest`.

### Step I-1.3 — Publishing pipeline hardening [P0] ✅ Done
- `PublishPostJob`: add `$tries`, exponential `$backoff`, `$timeout`, `$maxExceptions`, and
  `ShouldBeUnique` keyed by post id. Add a `failed()` handler that moves the post to `failed`, records
  the error on the activity log and notifies, so a crashed job never leaves a post in `publishing`.
- Lock the post row (`lockForUpdate()`) when moving `scheduled → publishing`, so two workers can't
  both pass the status check.
- Fan out per target: `PublishPostTargetJob` per `PostTarget` (batched with `Bus::batch()`). One
  platform's failure then doesn't block the others, and a retry only resends failed targets.
- Add a `partially_published` post status (in `PostStatusTransitionMap`, labels, badges, Home,
  analytics), plus a **Retry failed targets** action that re-dispatches only `failed` targets.
- Add an idempotency key per target (`post_targets.publish_attempt_id`) and send it to the vendor
  where supported, so a retried HTTP call can't double-post.
- Vendor HTTP client (`UploadPostAdapter::client()` and the music providers): add `->timeout()`,
  `->connectTimeout()`, and `->retry()` on 429/5xx with jitter (the DeepSeek provider already does
  this). Make the values configurable in `config/publishing.php`.
- Close the "stuck in `publishing`" gap noted in `publishing.md`: `PollPendingPublishStatusesAction`
  marks a target `failed` ("No delivery confirmation after N hours") past a configurable max age and
  raises a Home row.
- **Done when**: tests cover retry/backoff on 5xx, `failed()` cleanup, one-target-fails-others-succeed
  ending in `partially_published`, retrying only failed targets, concurrent job runs publishing once,
  and the stuck-post timeout.
- Implemented via `PublishPostJob` (unique per post + dispatch id, tries/backoff/timeout/
  maxExceptions, row lock, `failed()` cleanup) fanning out `PublishPostTargetJob`s in a
  `Bus::batch()`; `post_targets.publish_attempt_id` + `publish_requested_at` (at-most-once per
  attempt, sent as `Idempotency-Key`); new `partially_published` status;
  `ResolvePostPublishOutcomeAction`; `RetryFailedPostTargetsAction` +
  `POST posts/{post}/retry-failed-targets` + an editor "Retry failed channels" row;
  `App\Support\Publishing\VendorHttp` timeouts/jittered retries (publish POST retries on 429
  only) configured in `config/publishing.php`; the delivery-confirmation timeout in
  `PollPendingPublishStatusesAction`. Tests: `PublishPipelineTest`,
  `UploadPostAdapterResilienceTest`, `PublishPostJobTest`. See `.junie/modules/publishing.md`.

### Step I-1.4 — Transition atomicity and side effects after commit [P0] ✅ Done
- `TransitionPostStatusAction`: reload the post with `lockForUpdate()` inside the transaction and
  re-check `PostStatusTransitionMap::isAllowed()` against the **locked** status. Today two
  simultaneous decisions (review link + portal) can both pass.
- Move every side effect out of the transaction: `SendClientReviewRequestedEmailAction` (currently
  sends mail **inside** `DB::transaction()`), notifications, and job dispatch. Use
  `DB::afterCommit()` or `->afterCommit()` on queued mail/jobs.
- Queue every mailable (`ShouldQueue`) and every notification (`ShouldQueue` + `afterCommit`). Today
  only `OrganizationWelcomeMail` is queued, so a slow Resend call blocks the user's request (approve,
  invite, transition).
- Apply the same pattern to `CreateClientInvitationAction` and `CreateStaffInvitationAction`, which
  also send inside a transaction.
- **Done when**: a test fires two conflicting transitions and asserts exactly one succeeds and the
  other gets a clean 409/validation error. Tests assert no mail/notification is sent when the
  transaction rolls back, and that mail is queued (`Mail::assertQueued`).
- Implemented via `TransitionPostStatusAction` locking the row and re-checking the locked status
  (`InvalidPostTransitionException` → 409 JSON / redirect-back error), every side effect in
  `DB::afterCommit()`, every mailable and notification `ShouldQueue`, and `after_commit => true` on
  every queue connection. The invitation actions already sent after their transaction; queued mail
  + `after_commit` covers them inside outer transactions too. Tests: `TransitionAtomicityTest`.

### Step I-1.5 — Protect financial and audit records; soft deletes [P0] ✅ Done
- Add `SoftDeletes` to `Client`, `Post`, `Campaign`, `Asset`, `SocialAccount`, `Invoice`, and
  `Organization` (migrations add `deleted_at`).
- Change `invoices.client_id` (and `invoice_items` via invoices) from `cascadeOnDelete` to
  `restrictOnDelete`. Invoices are legal records and must outlive a deleted client.
- Replace "Delete client" with **Archive client** (`ClientStatus::Archived` + soft delete). That
  hides it everywhere, pauses publishing and reminders, keeps invoices, and is reversible from an
  "Archived" filter. Hard delete becomes an Owner-only purge, blocked while any invoice exists, done
  by a `PurgeClientAction` (transaction, logged, removes stored files via `AssetStorage`).
- Move `ClientController::destroy`'s inline `$client->delete()` into an Action (AGENTS.md rule 1).
- Super admin org delete: same treatment (soft delete, then a purge job that removes files, as
  `super-admin.md` notes is missing).
- Add a post delete/archive route: `PostPolicy::delete` exists but nothing calls it (`posts.md` gap).
  Published posts can only be archived, never deleted.
- **Done when**: deleting a client with invoices keeps the invoices queryable, archived clients
  restore cleanly, purge is blocked while invoices exist, and the post delete/archive endpoints are
  tested for each role.
- Implemented via `SoftDeletes` on the seven models; `invoices.client_id` → `NO ACTION` (not
  RESTRICT, so an organization purge can still cascade); `ArchiveClientAction` /
  `RestoreClientAction` / `PurgeClientAction` (+ `clients.restore` / `clients.purge` routes, an
  "Archived" roster view); archived clients pause publishing/reminders and close their portal and
  links; `DeleteOrganizationAction` soft-deletes and dispatches `PurgeOrganizationJob` (removes
  files via `StoredFileInventory` → `AssetStorage`, then hard-deletes); `DELETE posts/{post}`
  (`DeletePostAction`, published posts archive only) with an editor action. Tests:
  `ClientArchiveTest`, `PostDeleteTest`.

### Step I-1.6 — Secrets, tokens and private media [P0] ✅ Done
- `SocialAccount`: add `#[Hidden(['meta_access_token', 'external_profile_id'])]`. Extend the Step
  0.15 secret-leak test to every page that renders accounts.
- Stop persisting the raw review URL: drop `post_activity_logs.review_url` and store only the
  `review_token_id`. "Resend review email" mints a fresh token (and revokes the previous ones for
  that post) instead of replaying a stored URL. Revoke older unexpired tokens on every new
  `waiting_client` transition (`posts.md` gap).
- Upload-Post connect callback (`SocialAccountController::callback`): issue a signed, single-use
  `state` (cached against the account id and user id, 10-minute TTL) in `ConnectSocialAccountAction`.
  Reject callbacks whose `state` is missing or reused. Apply the same check to the Meta static
  callback (`auth/meta/callback`).
- Private media: add an `s3`-backed (or `local` private) `AssetStorage` implementation. Store
  assets, invoice PDFs, brand persona PDFs and AI images privately, and serve them via
  `temporaryUrl()` (vendor-facing publish URLs get a long enough TTL to cover the vendor fetch).
  Invoice PDFs in the portal go through an authorized download route. Needs
  `league/flysystem-aws-s3-v3`, which isn't installed yet.
- Organization settings: show the last 4 characters of stored keys (`settings.md` gap), and add a
  "Test connection" button for Upload-Post and the AI keys.
- **Done when**: tests prove (a) no prop anywhere contains `meta_access_token`, (b) no DB column holds
  a raw review token, (c) a forged callback without valid `state` is rejected, (d) a guessed asset or
  PDF URL without a signature returns 403/404.
- Implemented via `#[Hidden]` on `SocialAccount`; `post_activity_logs.review_url` replaced by
  `review_token_id` (resend mints a fresh token, older tokens revoked on every send);
  `ConnectStateStore` signed single-use `state` on the Upload-Post and Meta callbacks; private
  `local` disk by default with signed `/files/*` URLs, `StoredFileUrl` accessors, a vendor-length
  signed URL for publishing, and authorized invoice PDF routes (internal + portal); settings show
  key last-4 and a Upload-Post "Test connection". `league/flysystem-aws-s3-v3` was **not**
  installed (no new dependency) — S3 is a config switch once it is. No AI-key test: the only
  per-org AI key has no provider yet. Tests: `SecretsAndPrivateMediaTest`, connect-state tests in
  `SocialAccountControllerTest` / `FacebookInstagramConnectControllerTest`.

### Step I-1.7 — Rate limiting and HTTP semantics [P1] ✅ Done
- Named limiters in `AppServiceProvider` (per user and per org): `ai` (captions/trends/ideas),
  `ai-images`, `music-search`, `exports` (CSV/PDF for analytics, invoices and reports),
  `public-token` (review, client-invite and staff-invite `GET`/`POST`, per IP + token hash), and
  `webhooks` (per IP).
- Change AI trends/ideas from `GET` to `POST`. They call a paid vendor and upsert rows, and a `GET`
  can be prefetched, crawled, or replayed by the browser. Captions also move to `POST`. Update the
  Wayfinder routes and frontend callers.
- Return a structured 429 the frontend can display ("You've hit the AI limit for this minute; try
  again in 40s").
- **Done when**: tests assert each limiter trips at its threshold and that AI `GET`s now return 405.
- Implemented via `App\Support\RateLimits` (`ai`, `ai-images`, `music-search`, `exports`,
  `public-token`, `webhooks`) with one structured 429 (`message` + `retry_after`, or an error toast
  for Inertia visits); AI trends/ideas/captions moved to POST (GET → 405) with the frontend callers
  updated. Tests: `RateLimitingTest`. See `.junie/modules/ai.md`.

### Step I-1.8 — Scheduler safety [P0] ✅ Done
- `routes/console.php`: add `->withoutOverlapping()->onOneServer()` to every scheduled command (poll,
  health check, briefs, reminders, prune, scheduled reports). `onOneServer()` needs a shared cache
  store, which I-2.9 provides.
- Make long-running commands chunk with `lazyById()` instead of loading every row
  (`CheckAllSocialAccountsHealthAction`, `PollPendingPublishStatusesAction`,
  `GenerateDailyBriefsAction`, `SendUpcomingPostReminderEmailsAction`).
- Add a scheduler heartbeat (each run writes `last_ran_at` to cache) and a super-admin health tile
  showing stale schedulers and queue backlog.
- **Done when**: a test asserts every `Schedule::command()` event has overlap protection
  (introspect `Schedule::events()`), and the heartbeat tile goes red when a command hasn't run within
  two of its intervals.
- Implemented via one helper in `routes/console.php` giving every scheduled command
  `withoutOverlapping()->onOneServer()` and an `onSuccess` heartbeat
  (`App\Support\Scheduling\SchedulerHeartbeat`); `lazyById()`/per-org chunking in the four
  long-running actions; a "System health" panel on the super admin dashboard (stale schedulers,
  queue backlog). `onOneServer()` relies on a shared lock-capable cache (database today; I-2.8).
  Tests: `SchedulerSafetyTest`. See `.junie/modules/super-admin.md`.

### Step I-1.9 — Tenant-isolation safety net [P0] ✅ Done
- Isolation today relies on every query remembering `where('org_id', …)` and every policy comparing
  `org_id`. Add a `BelongsToOrganization` trait with a global scope tied to the authenticated user's
  org (skipped for super admins, jobs, and the webhook, via an explicit `withoutOrgScope()`). Apply it
  to every org-owned model.
- Add a generated cross-tenant test: for every route with a bound model parameter, create the model
  in org B, request it as each org-A role, and assert 403/404. Running it over `Route::getRoutes()`
  means new routes are covered automatically.
- **Done when**: the generated test passes for every bound route, and deliberately removing one
  `org_id` check makes it fail.
- Implemented via `BelongsToOrganization` + `OrganizationScope` on 16 org-owned models
  (skipped for guests/jobs/commands/webhooks and super admins; `withoutOrgScope()` to cross on
  purpose), with route binding and child→parent relations unscoped so policies stay the authority
  (403, not 500). The generated `CrossTenantRouteTest` walks `Route::getRoutes()` for every bound
  route × every org-A role; writing it surfaced validate-before-authorize endpoints (fixed) and
  unscoped `exists:` rules that let another org's asset be attached to a post (fixed). Verified
  that replacing `CampaignPolicy::view` with `return true` makes it fail. See
  `.junie/modules/organizations-users.md`.

---

## Phase I-2 — Database & query performance [P1]

Goal: every page loads in under 300 ms server time and under 500 KB of props for an agency with 50
clients and 20,000 posts. Payloads scale with what's visible on screen, not with history.

### Step I-2.1 — Index every foreign key and hot filter (MySQL) [P1] ✅ Done
- MySQL (InnoDB) creates an index for each `foreignId()->constrained()` column, but the composite
  and non-FK hot filters have none, so the Home, calendar and scheduler queries scan more rows than
  they need. Add a migration indexing at least:
  - `posts`: `(client_id, status)`, `campaign_id`, `created_by`
  - `post_targets`: `social_account_id`, `scheduled_at_utc`, `(status, updated_at)`,
    `external_post_id`
  - `social_accounts`: `(client_id)`, `(org_id, connection_status)`
  - `campaigns`: `client_id`; `assets`: `(client_id, created_at)`, `campaign_id`, `uploaded_by`
  - `invoices`: `client_id`; `invoice_items`: `invoice_id`, `post_id`
  - `post_comments`, `post_approvals`, `post_assets`: `post_id` / `asset_id`
  - `post_comment_mentions`: `user_id`; `review_tokens`: `post_id`, `client_id`
  - `users`: `org_id`, `client_id`; `client_invitations`: `client_id`; `staff_invitations`: `org_id`
  - `clients`: `(org_id, name)`, `owner_user_id`
  - `ai_trends`/`ai_post_ideas`/`daily_briefs`: `(org_id)`, `ai_post_ideas.post_id`
  - `brand_brain_reference_assets`: `asset_id`; `ai_image_variants`: `asset_id`, `parent_variant_id`
- Add a permanent test that fails if any FK column lacks a leading-column index, so new
  migrations can't regress this.
- **Done when**: the catalog test passes, and `EXPLAIN` on the Home, calendar and upcoming-post
  queries shows index scans on a seeded 20k-post dataset (record the before/after in
  `analytics.md`/`home.md`).
- Implemented via `2026_10_18_120000_add_hot_path_indexes.php` (MySQL already indexes FK
  columns, so it adds the composite/non-FK hot filters and covers any FK still missing a leading
  index) and `tests/Feature/Database/IndexCoverageTest.php` (runs on SQLite and MySQL). `EXPLAIN`
  results and a 50k-post benchmark are in `.junie/modules/ops.md`. The project runs on MySQL 8.

### Step I-2.2 — Home / needs-attention in SQL, not PHP [P1] ✅ Done
- `GetNeedsAttentionItemsAction` loads every post (including years of `published`/`archived`) with
  targets, accounts and assets, then filters in PHP and runs the checklist per post. Rewrite it as
  one bounded query per bucket:
  - failed / internal review / waiting client: `where status = ?` + `limit` + a separate `count()`
  - missing media: `whereIn(status, active)->whereDoesntHave('assets')` plus a subquery for
    "has at least one non-text-only target". Reuse a new `Platform::textOnlyCapableValues()` so the
    rule stays shared with `EvaluatePostChecklistAction`.
- Return `{items, total}` per bucket and add "View all" links into the Content hub (I-4.1) with the
  matching filter.
- Apply the same treatment to `GetDashboardNeedsAttentionListAction`. The dashboard currently builds
  a 100-row list just to `count()` it.
- **Done when**: Home runs a fixed number of queries regardless of post count (assert with
  `DB::getQueryLog()`), and the existing Home tests still pass.
- Implemented via bounded per-bucket queries in `GetNeedsAttentionItemsAction` (`{items, total}`),
  `Platform::textOnlyCapableValues()`, and `GetDashboardNeedsAttentionListAction` returning
  `{rows, total}`. "View all" links wait on the Content hub (I-4.1). See `home.md`.

### Step I-2.3 — Calendar loads only the visible window [P1] ✅ Done
- `CalendarController::index` loads every post with no date bound. Add required `from`/`to` params
  (defaulting to the visible month ± 7 days), filter on `post_targets.scheduled_at_utc` (or local
  date for unscheduled times), and select only the columns `BuildCalendarOccurrencesAction` needs.
- Add an "Unscheduled" side drawer (paginated) for posts without dates, which currently can't be
  seen on the calendar at all.
- `reschedulablePostIds` runs a policy per post. Compute it as a set rule
  (`status not in [...] and no published target`) in SQL.
- Navigating months does a partial reload (`only: ['occurrences']`) instead of a full visit.
- **Done when**: the payload size is independent of total history (test with 5k posts outside the
  window), and month navigation keeps filters.
- Implemented via `CalendarWindow`, `CalendarRequest`, `GetCalendarPostsAction` (incl. set-based
  reschedulable ids and the paginated Unscheduled drawer) and partial-reload month navigation in
  `content-calendar.tsx`; the portal calendar is windowed too. See `calendar.md`.

### Step I-2.4 — Client page: split, lazy, paginated [P1] ✅ Done
- `ClientController::show` builds every post, asset, invoice (with items), campaign, invitation, AI
  trend and AI idea up front, for a single React page of 2,639 lines (`pages/clients/show.tsx`).
- Give each tab its own route (`/clients/{client}/posts`, `/assets`, `/campaigns`, `/billing`,
  `/accounts`, `/portal-access`, `/ai`, `/briefs`). Deep links then work, the back button works, and
  each tab only loads its own data. A shared `ClientLayout` keeps the header and health pill.
- Within a tab, use paginated queries and `Inertia::defer()`/`Inertia::optional()` for secondary data
  (AI trends/ideas, invoice items).
- Move the seven private `transform*` methods out of the controller into presenters (like
  `InvoicePresenter`) or `JsonResource`s so other controllers reuse them. `PortalClientController`
  and the campaign pages repeat similar shapes today.
- **Done when**: the client overview first load is under 100 KB of props for a client with 2k posts,
  each tab has its own feature test asserting the props shape and authz, and `clients/show.tsx` is
  split into per-tab page files with none over ~400 lines.
- Implemented via `ClientTabController` + `BuildClientPageHeaderAction`, `ClientPresenter` /
  `ClientTabPresenter`, `ClientLayout` and `pages/clients/tabs/*` (all under ~400 lines). Routes
  differ slightly from the sketch: assets live at `/media` and briefs at `/briefs` because
  `/assets` and `/daily-briefs` were already JSON endpoints; `/clients/{client}` is the accounts tab.
  AI trends/ideas are deferred props. See `clients.md`.

### Step I-2.5 — Clients index at scale [P1] ✅ Done
- Paginate (cursor or offset, 25/50/100), add server-side search (name, legal name, tags), sorting
  (name, health, owner, start date, retainer), and filters (status, health, owner, tag).
- `ClientHealthService` runs per row in PHP. Materialize the result into `clients.health_status`,
  `clients.health_reason` and `clients.health_computed_at`, recomputed by a
  `RecomputeClientHealthAction` on the events that affect it (account status change, post failure,
  invoice overdue, nightly sweep). Health is then sortable, filterable, and free to read.
- Add a `health_history` table (one row per change) for a health-over-time sparkline (feeds I-6.1).
- **Done when**: the index is paginated, filterable and sortable server-side, and health is read
  from the column, with a test asserting recompute fires on each trigger event.
- Implemented via `ListClientsAction` + `ClientIndexRequest`, `clients.health_*` +
  `client_health_history`, `RecomputeClientHealthAction` (client and social-account triggers plus
  the nightly `clients:recompute-health`). Post-failure and overdue-invoice triggers arrive with
  the I-6.1 rules that use them. See `clients.md`.

### Step I-2.6 — Hot-path micro-optimizations [P1] ✅ Done
- `PostController::hashtagSuggestions` plucks the hashtags of every post the client has ever made.
  Replace it with a `client_hashtags` table (`client_id`, `tag`, `uses`, `last_used_at`) maintained
  by `UpdatePostAction`/`CreatePostAction`, read top 50 by usage and recency. It also feeds I-9's
  hashtag research.
- Shared Inertia props (`HandleInertiaRequests`) run `unreadNotificationsCount` and the portal
  `pendingApprovals` count on every request. Cache both per user for 30–60 s and bust the cache on
  notification create/read and on transitions.
- `auth.user` shares the full `User` model. Share an explicit whitelist (`id`, `name`, `email`,
  `role`, `avatar`, `org_id`, `client_id`, two-factor flag) so new columns never leak by default.
- `PostController::edit` lazy-loads `$post->client` and `$client->brandBrain`. Eager-load them.
- Turn on `Model::preventLazyLoading(! app()->isProduction())` and
  `Model::preventSilentlyDiscardingAttributes()` in `AppServiceProvider`, and fix whatever surfaces.
- **Done when**: the test suite passes with lazy-loading prevention on, and the shared-props query
  count per request is ≤ 2 (asserted).
- Implemented via `client_hashtags` + `ClientHashtagService`, `SharedPropsCache`, the `auth.user`
  whitelist, eager-loaded editor relations, and lazy-loading / discarded-attribute prevention
  outside production. See `posts.md` and `notifications.md`.

### Step I-2.7 — Analytics aggregation in SQL [P2] ✅ Done (cold benchmark target open)
- `analytics.md` and `super-admin.md` both note that turnaround medians, bucketing and health are
  computed in PHP, and super admin uses `whereIn` lists of org ids. Move these to SQL
  (`date_trunc`, `percentile_cont` for medians, `FILTER` clauses, window functions) inside the
  existing shared analytics services, without changing their output shape.
- Cache each summary per `(org, filters hash)` for 5 minutes, tagged by org. Bust it on transitions
  and invoice changes.
- **Done when**: every existing analytics test passes unchanged (output parity), and a 50k-post
  benchmark runs the org summary in under 500 ms.
- Implemented via window-function approvals, SQL series bucketing, SQL days-to-pay and stored
  health (MySQL-compatible: no `percentile_cont`/`FILTER`/`date_trunc`), plus `AnalyticsCache`
  (per-org version key; works on every cache store). Output parity: every existing analytics test
  passes unchanged. The cold 50k-post summary is ~1.3 s, not < 500 ms — needs `org_id` on
  `post_activity_logs` (see `analytics.md`). Platform analytics' `whereIn` lists are unchanged.

### Step I-2.8 — Infrastructure drivers [P1] ✅ Done
- Move queue, cache, session and rate limiter from the `database` driver to Redis. Install Laravel
  Horizon, with named queues: `publishing` (highest priority, its own workers), `webhooks`,
  `notifications`, `mail`, `ai`, `reports`, `default`.
- Give each job a `$queue` and each queue its own timeout/retry profile.
- Document the production `php.ini` limits needed for video uploads (`assets.md` gap) in a
  new `.junie/modules/ops.md`, and remove the dependency on them via direct-to-storage uploads
  (I-7.1).
- **Done when**: `.env.example` and `config/*` default to Redis for production, Horizon is behind
  super-admin auth, and the publishing queue is isolated from AI and report jobs.
- Implemented via Redis defaults (`config/*`, `.env.example`), Laravel Horizon with per-workload
  supervisors (`config/horizon.php`), `App\Support\Queues` names on every job/mailable/
  notification, and a super-admin-only dashboard. See `.junie/modules/ops.md`.

---

## Phase I-3 — Publishing engine depth [P1/P2]

Goal: publishing is the product's core promise. Make it correct per platform, recoverable, and
broad enough that agencies never need a second tool.

### Step I-3.1 — Per-platform caption and field variants [P1] ✅ Done
- **Implemented via Step M-2.2** in `mvp.md` (20 Oct 2026).
- Today a post has one `master_caption` and one hashtag list for every target, and each network gets
  the same text. Every market leader lets you tailor each platform.
- Add nullable `caption_override`, `hashtags_override`, `first_comment`, `alt_text` (per asset) and
  `link_url` to `post_targets`. The effective value is the override or the master
  (`PostTarget::effectiveCaption()`).
- Editor: a "Customize for {platform}" tab per target, showing the character count against that
  platform's limit, a "Reset to master" control, and a diff indicator when it differs.
- `EvaluatePostChecklistAction` checks length, hashtag count and link policy per target, against the
  effective values.
- `UploadPostAdapter` sends the effective caption plus `first_comment` per target (it currently notes
  "No `first comment` field exists on `Post`"). AI suggestions already return `altText`/`firstComment`
  (see `ai.md`). Persist them through these new fields.
- **Done when**: a London/Dubai-style acceptance test creates one post with an Instagram override and
  a LinkedIn master caption, and asserts each vendor payload carries the right text and first comment.

### Step I-3.2 — Explicit format per target (feed / reel / story / carousel / short) [P1] ✅ Done
- **Implemented via Step M-2.3** in `mvp.md` (20 Oct 2026).
- The Instagram format is inferred from the first attached asset's type, and `STORIES` is never sent
  (`publishing.md`). Add `post_targets.format` (enum per platform, from a capability registry,
  I-3.3).
- Add `post_assets.sort_order` for carousel ordering, plus a cover/thumbnail asset and offset for
  video.
- Checklist rules per format (story = 9:16, reel = video, carousel = 2–10 items, and so on), with
  clear messages.
- **Done when**: stories and carousels publish with correct ordering in faked-vendor tests, and the
  checklist rejects mismatched formats.

### Step I-3.3 — Platform capability registry [P1] ✅ Done
- **Implemented via Step M-2.1** in `mvp.md` (20 Oct 2026).
- `Platform::captionLimit()`, `maxHashtags()`, `allowsLinksInCaption()` and `isTextOnlyCapable()` are
  hand-picked constants (`ai.md` and `posts.md` gaps). Replace them with `config/platforms.php`
  (limits, formats, media constraints: aspect ratios, max duration and size, codecs, carousel bounds,
  link policy, first-comment support, scheduling support), exposed via a
  `PlatformCapabilities` service and shared with the frontend as a single prop.
- One source of truth for the checklist, the editor counters, the AI guardrails and the adapter.
- **Done when**: no platform rule is hard-coded outside the registry (grep test), and changing one
  config value changes editor, checklist and AI behaviour together.

### Step I-3.4 — More networks [P2] ✅ Done
- **Implemented via Step M-2.6 (Bluesky deferred)** in `mvp.md` (20 Oct 2026).
- `Platform` currently covers Instagram, TikTok, Facebook and LinkedIn. Upload-Post also supports
  X/Twitter, YouTube (Shorts), Pinterest, Threads, Bluesky and Google Business Profile. Add them one
  at a time behind `PlatformRolloutStage` flags (the Step 1.7 pattern), each with its field mapping,
  capability-registry entry, and a faked-vendor publish test.
- Priority by agency demand: YouTube Shorts → Threads → Pinterest → X → Google Business Profile →
  Bluesky.
- **Done when**: each new platform has a passing publish test and a rollout flag, and the editor
  shows it only when enabled.

### Step I-3.5 — Media validation and processing before publish [P1] ✅ Done
- **Implemented via Step M-2.4** in `mvp.md` (20 Oct 2026).
- `assets.width`/`height` exist but are never filled (`assets.md`). Probe dimensions, and
  duration and codec for video (ffprobe), in a queued `ProbeAssetJob` after upload.
- Validate each target's media against the capability registry in the checklist (wrong aspect ratio,
  too long, too large), and offer one-click fixes where possible (crop to 4:5/9:16/1:1, using the
  `intervention/image` library already installed).
- Generate thumbnails and responsive previews, so lists stop loading full-resolution originals.
- **Done when**: an over-length TikTok video and a 16:9 story are both blocked with a specific reason,
  and thumbnails are used on every list and grid.

### Step I-3.6 — Cancel, edit-after-schedule and vendor sync [P1] ✅ Done
- **Implemented via Step M-2.5** in `mvp.md` (20 Oct 2026).
- Implement `UploadPostAdapter::cancel()`. Change the contract to take the `PostTarget` (or
  `SocialAccount`) so it can authenticate per org (`publishing.md` gap).
- Unscheduling or editing a vendor-held post cancels and re-submits it (ties into I-1.2).
- Auto-register the org webhook via `POST /uploadposts/users/notifications` when the Upload-Post key
  is saved, and store the returned secret. No more manual copying (`publishing.md` gap).
- **Done when**: tests cover cancel on unschedule, re-submit on edit, and webhook auto-registration
  storing the secret.

### Step I-3.7 — Queue slots and posting windows [P2]
- `social_accounts.posting_windows` exists but nothing uses it. Add **Add to queue**: drop a post into
  the next free posting window per target account, in that account's timezone, honouring the
  dual-timezone invariant.
- A per-account queue view shows the upcoming slots and the gaps.
- **Done when**: queueing three posts for two accounts in different timezones fills each account's
  next windows independently (test).

### Step I-3.8 — Approval mode semantics [P1] ✅ Done
- **Implemented via Step M-2.9** in `mvp.md` (20 Oct 2026).
- `ApprovalMode` (`internal_only` | `client_required`) isn't consulted anywhere (`posts.md`).
  `internal_review → approved` is allowed for internal roles even when the client must approve.
  Make `PostStatusTransitionMap` approval-mode aware: `client_required` posts can only reach
  `approved` via `waiting_client`. Add an Owner-only "override approval" transition that requires a
  reason and is recorded in the activity log.
- Default the approval mode per client (`clients.default_approval_mode`).
- **Done when**: transition tests cover both modes and the override path.

### Step I-3.9 — Token lifecycle [P2] ✅ Done
- **Implemented via Step M-2.7** in `mvp.md` (20 Oct 2026).
- `meta_access_token_expires_at` is stored but never acted on. Warn 7 days and 1 day before expiry
  (notification + Home row + client health Amber), and auto-refresh long-lived tokens where Meta
  allows it.
- The hourly health check also covers the Meta token and records `last_health_check_at` and
  `last_health_error` on the account, shown in the UI.
- **Done when**: an account expiring in 5 days produces a Home row and an Amber health reason (test).

---

## Phase I-4 — Content workflow & editor [P1]

Goal: the daily loop (draft → review → approve → schedule) should feel faster than any competitor.

### Step I-4.1 — Content hub: a top-level Posts area [P1] ✅ Done
- **Implemented via Step M-5.1 in `mvp.md` (23 Oct 2026):** the hub lives at `/content` (the business
  workspace already owns `/posts`), as a section of the 04 Clients chapter. Table + board views,
  server-side filters, CSV export and personal saved views; the board reuses the C-4.3 `KanbanBoard`
  and the bulk bar reuses `BulkTransitionPostsAction` (reporting every skip). **Assignee** filter and
  "AI-generated" filter are deferred to M-5.4 (no `posts.assignee_id`/AI flag yet). See `posts.md` ›
  Phase M-5 additions and `tests/Feature/Content/`.
- There's no "Posts" chapter in `resources/js/lib/chapters.ts`. Posts can only be reached through a
  client page or the calendar. Add `/posts` (`PostIndexController` + `ListPostsAction`) with:
  - a **table view** (client, campaign, status, targets, scheduled time, owner/assignee, updated),
    paginated, server-side filters (client, campaign, status, platform, assignee, date range, has
    media, AI-generated), and saved views;
  - a **board view** (kanban by status). Dragging between columns calls the transition endpoint and
    respects `allowedTransitions`;
  - **bulk actions**: move status, assign, add to campaign, reschedule by N days, archive, export.
- **Done when**: feature tests cover filters, pagination and authz per role, a bulk transition skips
  (and reports) posts whose transition isn't allowed, and the board renders only legal drop targets.

### Step I-4.2 — Faster post creation [P1] ✅ Done
- **Implemented via Step M-5.2 in `mvp.md` (23 Oct 2026):** `store` already opens the editor. Entry
  points: client-page `NewPostDialog`, the content hub's "New post for…" picker, an agency
  command-palette "New post", AI ideas, and `campaign_id`/date prefill via `clients.posts.store`.
  `DuplicatePostAction` + `posts.duplicate` copies content, media and targets into a fresh draft (no
  dates/status), from the editor header and the content-hub row menu. Calendar empty-slot create is
  delivered in M-5.7 (I-5.1). See `posts.md` › Phase M-5 › M-5.2 and `tests/Feature/Posts/DuplicatePostTest.php`.
- `PostController::store` redirects back to the client page. It should open the editor for the new
  post.
- Create a post from anywhere: the command palette ("New post for…"), the content hub, a calendar
  day/time cell (prefilled date/time), an AI idea (exists), and a campaign page (prefilled campaign).
- **Duplicate post** (copies content, assets and target accounts, but not dates or statuses).
- **Done when**: each entry point is covered by a feature test that lands on `posts.edit` with the
  right prefill.

### Step I-4.3 — Autosave, version history and change diffs [P1] ✅ Done
- **Implemented via Step M-5.3 in `mvp.md` (23 Oct 2026):** `post_revisions` written by
  `UpdatePostAction` (deduped), 5s debounced autosave + "Saved · HH:MM", a History drawer with diffs
  and restore, and a "what changed since last review" diff in the portal and review link (diff of the
  two most recent shared revisions). See `posts.md` › Phase M-5 › M-5.3.
- The editor has an unsaved-changes guard but no autosave. Add debounced autosave (every 5 s while
  dirty) for draft-like statuses, with a "Saved · 12:04" indicator.
- Add `post_revisions` (caption, hashtags, per-target overrides, assets, author, created_at), written
  by `UpdatePostAction`. The editor gets a History drawer with diffs and a restore option.
- On `changes_requested → draft`, show the reviewer what changed since they last saw it (diff
  against the revision at `waiting_client`), both in the portal and in the review link.
- **Done when**: tests cover revision creation per save, restore, and the "what changed" diff payload
  in the portal.

### Step I-4.4 — Assignees, due dates and "My work" [P1] ✅ Done
- **Implemented via Step M-5.4 in `mvp.md` (23 Oct 2026):** `posts.assignee_id`/`reviewer_id`/`due_at`,
  targeted internal-review notifications, a `/my-work` page (assigned / to-review / due / mentioned),
  content-hub assignee filter + column + bulk assign, and the C-4.4 deliverable → post handoff. See
  `posts.md` › Phase M-5 › M-5.4.
- Add `posts.assignee_id`, `posts.due_at`, and optionally `posts.reviewer_id` (internal reviewer).
  Notifications go to the assignee or reviewer instead of every org user (`notifications.md` gap).
- A **My work** page (also the default Home section for Designers): assigned to me, waiting on my
  review, mentioned me, due soon or overdue.
- **Done when**: assignment notifications target only the assignee, and the My work page is tested
  per role.

### Step I-4.5 — Live per-platform previews [P2] ✅ Done
- **Implemented via Step M-5.5 in `mvp.md` (M-1.6 + M-2.2; verified 23 Oct 2026):** `PreviewPanel` is a
  first-class editor side panel with per-target tabs, effective caption, crop, "…more" truncation and
  first comment from the capability registry; the same `SocialPostPreview` is reused in the portal and
  review link. A mobile/desktop toggle (needing per-platform desktop layouts) is deferred.
- `components/social-post-preview.tsx` exists. Make it a first-class side panel in the editor, with
  one tab per target showing the effective caption, media crop, truncation ("…more") and first
  comment, plus a mobile/desktop toggle.
- Reuse the same component in the portal and the review link, so clients approve what they'll
  actually see.
- **Done when**: previews render for every supported platform/format from the capability registry,
  and the portal uses the same component.

### Step I-4.6 — Editor decomposition and ergonomics [P2] ✅ Done
- **Implemented via (20 Oct 2026):** Step M-1.6 in `mvp.md` — see `posts.md` › Phase M-1 additions.
- Split `pages/posts/edit.tsx` (908 lines) into feature components: composer, targets and schedule,
  media, checklist, workflow, discussion, AI, image studio.
- Replace the 19 native `type="date"`/`type="time"` inputs with a shared `DateTimePicker` that
  shows the account's timezone and the viewer's equivalent, and offers the "same instant across
  accounts" opt-in described in spec §4.6.
- Keyboard shortcuts (⌘S save, ⌘↵ submit for review, ⌘K palette) and undo toasts for destructive
  actions.
- **Done when**: no editor file is over ~300 lines, the picker is used everywhere a date/time is
  chosen, and shortcuts are listed in a `?` help dialog.

### Step I-4.7 — Bulk import and recycling (pull Phases 10/11 forward) [P2] ✅ Done
- **Implemented via (23 Oct 2026, MVP Step M-5.8):** Phase 10 CSV import (`ParseBulkPostImportAction`,
  `CreatePostsInBulkAction`, `BulkPostImportController`, `BulkPostRow`) and Phase 11 recycling
  (`RecyclePostAction`, Home bucket), plus recurring posts (`GenerateRecurringPostDraftsAction`).
  Import/recycling carry per-target overrides and formats. See `posts.md`.
- Build Phase 10 (CSV bulk import) and Phase 11 (recycling) as written in the development plan, with
  two changes: the import accepts per-target overrides (I-3.1) and formats (I-3.2), and recycling
  copies per-target overrides as well.
- **Done when**: the Phase 10/11 exit criteria are met.

---

## Phase I-5 — Calendar [P1/P2]

### Step I-5.1 — Interaction upgrade [P1] ✅ Done
- **Implemented via (23 Oct 2026, MVP Step M-5.7):** `@dnd-kit` pointer + touch drag, week/day time
  grids, single-target moves (`RescheduleTargetAction` + `posts.targets.reschedule`), a
  keyboard-accessible reschedule popover, and empty-slot create (`CalendarNewPostDialog` +
  `calendar.new-post-options`). Tests `RescheduleTargetTest`, `reschedule-popover.test.tsx`. See
  `calendar.md`, `posts.md`.
- Replace native HTML5 drag and drop (pointer-only, no keyboard or touch, per `calendar.md`) with
  `@dnd-kit`. That adds keyboard and touch support, and dragging a single target as well as the
  whole post.
- Week and day views get a real time grid, so dragging changes the time as well as the date.
- Click an empty slot to create a post there (I-4.2). Once I-1.2 lands, scheduled posts can be
  dragged too.
- **Done when**: keyboard-only rescheduling works (tested with RTL/Playwright, I-14.6), and moving
  one target leaves its sibling targets unchanged (backend test).

### Step I-5.2 — Planning layers [P2]
- Campaign date ranges as bands across the grid, holidays and key dates per client country (a
  `key_dates` table, plus seeded public holidays), and gap highlighting ("no posts for Client X in
  the next 5 days", shared with AI Step 2.12).
- Posting-window shading per account (I-3.7).
- **Done when**: each layer can be toggled and is covered by a props test.

### Step I-5.3 — Sharing and export [P2]
- A tokenized iCal feed per org, client or account (read-only, revocable), a "Jump to date" control,
  print/PDF of a month for client meetings, and saved filter views.
- **Done when**: the iCal feed validates, honours the token's scope, and revokes cleanly (tests).

---

## Phase I-6 — Clients, Brand Brain, Social accounts [P1/P2]

### Step I-6.1 — Client health v2 [P1] ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.4):** weighted reasons in `ClientHealthService`, `clients.health_reasons` / `target_posts_per_week`, `Client::healthFor()` (Owner-only invoices), trend from `client_health_history`; see `clients.md`.
- The current health rules only use profile, billing and token fields (`clients.md`). Fold in the
  signals that now exist: overdue approvals (Phase 9), failed or partial publishes in the last 7 days,
  posting cadence below target (new `clients.target_posts_per_week`), calendar gaps in the next N
  days, unpaid overdue invoices (Owner-only reason), Brand Brain completeness, and token expiry soon.
- Weighted reasons, with the top reason shown and the full list on hover, plus a health trend from
  `health_history` (I-2.5).
- **Done when**: each new trigger has its own `ClientHealthServiceTest` case, and the reason list is
  ordered by weight.

### Step I-6.2 — Client contacts [P1] ✅ Done
- **Implemented via (2026-10-23, MVP Step M-6.1):** `client_contacts` with roles and per-channel
  email preferences, backfilled from `approval_email` / `billing_email`; review emails reach every
  approver contact. See `clients.md` › Phase M-6 and `mvp.md` Step M-6.1.
- `clients.approval_email` holds a single address, so only one approver gets review links. Add
  `client_contacts` (name, email, phone, role: approver / billing / marketing lead / other,
  `receives_review_emails`, `receives_invoices`), and migrate `approval_email`/`billing_email` into
  it.
- Portal invitations are sent to contacts. Review emails go to every approver contact (with a
  per-post override).
- **Done when**: migration parity tests pass and review emails reach all approver contacts.

### Step I-6.3 — Client timeline and onboarding checklist [P2]
- One client activity feed merging post transitions, approvals, comments, invoices, account
  connections and portal logins (built over the existing logs, plus I-12.4's audit log).
- An onboarding checklist per new client (Brand Brain filled, at least one account connected,
  approver contact invited, first post scheduled, retainer set), shown until complete.
- **Done when**: the feed is paginated and authz-filtered (billing events hidden from non-billing
  roles), and the checklist completion is tested.

### Step I-6.4 — Brand Brain v2 [P2]
- A completeness score per section, and version history with restore.
- Persona PDF text extraction for AI (deferred in `ai.md`/`brand-brain.md`): a queued
  `ExtractPersonaTextJob` stores truncated text that `ClientContextBuilder` includes.
- Voice examples: "posts we love" and "never like this", optionally linked to real posts, fed to AI
  as few-shot examples.
- "Draft from website": AI proposes Brand Brain fields from the client's site, which a human
  reviews and accepts.
- A shareable, client-approvable brand summary page in the portal.
- **Done when**: each sub-feature has tests, and persona text demonstrably reaches the prompt context
  (`ClientContextBuilder` unit test).

### Step I-6.5 — Social accounts management [P2]
- A dedicated accounts page per client and an org-wide connections dashboard (status, last check,
  last error, token expiry, last post). Bulk reconnect, a "Refresh status" button when the popup
  fallback loses its opener (`social-accounts.md`), and per-account defaults (hashtags, first
  comment, location).
- Use `persona_override` in `ClientContextBuilder`, so AI writes in the account's voice.
- **Done when**: the dashboard lists every org account with health, and `persona_override` is in the
  AI context (test).

---

## Phase I-7 — Assets & media library [P1/P2]

### Step I-7.1 — Direct-to-storage uploads [P1]
- Uploads currently stream through PHP (500 MB video limit, PHP ini dependent, per
  `assets.md`). Switch to presigned multipart uploads straight to S3, resumable, with a
  progress bar, then a server-side `ConfirmAssetUploadAction` that verifies size, type and hash.
- Builds on I-1.6's private storage.
- **Done when**: a 1 GB upload works without PHP ini changes, and confirmation rejects a mismatched
  MIME type or size (tests use a faked S3 disk).

### Step I-7.2 — Media library [P2]
- **Partly done (23 Oct 2026, MVP Step M-5.9):** the **Imports** bullet (Canva, Google Drive,
  Dropbox) is built — each behind a `DesignImportSource` contract (per I-15.2), imported assets go
  through the private pipeline + probe and can join a campaign kit. The rest of the dedicated media
  library (folders, usage tracking, rights-expiry warning, duplicate detection) is still open.
- A dedicated `/assets` library (org-wide, filterable by client) with grid/list views, folders or
  collections, tags, search (filename, tags, AI prompt), type and orientation filters, and a
  variant-group UI (`variant_group_id` exists without a UI).
- Usage tracking: "used in N posts", with links. Block deleting an asset used by a scheduled post.
- Rights and licensing: expiry dates and usage restrictions, with a warning when attaching an asset
  whose rights expire before the post date.
- Duplicate detection by content hash.
- Imports: Google Drive, Dropbox, Canva, and Figma frames rendered to images (Figma is currently only
  a link).
- **Done when**: each filter and the rights warning are tested, and deleting an in-use asset is
  blocked.

---

## Phase I-8 — Approvals & client experience [P1]

Goal: the approval experience is where agencies win or lose clients. Make it the best on the market.

### Step I-8.1 — One client experience, passwordless [P1] ✅ Done
- **Implemented via (2026-10-23, MVP Step M-6.2):** magic-link portal login (`portal_login_tokens`),
  review emails preferring it, invite-accept auto-login, and `portal.redirect` on the whole internal
  workspace. See `client-portal.md` › Phase M-6 and `mvp.md` Step M-6.2.
- Today there are two parallel surfaces, the token review link and the portal. Unify them: the review
  email contains a **magic link** that signs the contact into the portal, scoped to their client and
  landing on that post. Keep the token-only link for contacts without portal access.
- After accepting an invitation, redirect to `/portal` (`client-portal.md` gap).
- Fully block `Role::ClientReviewer` from the internal app (`/dashboard`, `/calendar`, `/posts/*`).
  `client-portal.md` notes they can still reach the read-only internal pages.
- **Done when**: a magic link signs in, scopes and expires correctly (tests), and every internal route
  redirects a client reviewer to the portal (generated route test, like I-1.9).

### Step I-8.2 — Multi-approver and staged approvals [P1] ✅ Done
- **Implemented via (2026-10-23, MVP Step M-6.3):** `approval_steps` per post + `clients.approval_policy`
  (any/all/sequential), `SubmitClientDecisionAction`, approve-all, and the approval certificate with
  the approved revision hash. See `client-portal.md` › Phase M-6 and `mvp.md` Step M-6.3.
- Approval policies per client: any one approver, all approvers, or sequential stages (e.g. Brand
  Manager → Legal). Add an `approval_steps` table per post, built from the client policy when the
  post enters `waiting_client`.
- **Approve all** / batch approve in the portal, with an optional comment per post.
- An approval certificate: a PDF or exportable record of who approved what and when, with the exact
  content version (ties into I-4.3). Regulated clients (finance, health) need this.
- **Done when**: all three policy modes are tested end to end, and the certificate contains the
  approved revision hash.

### Step I-8.3 — Visual feedback [P2] ✅ Done
- **Implemented via (2026-10-23, MVP Step M-6.4):** pins (image/video/caption), suggested edits,
  threaded resolve/unresolve, and unresolved-feedback-blocks-approval. See `posts.md` › Phase M-6
  (M-6.4) and `mvp.md` Step M-6.4.
- Pin comments on media (x/y on images, timestamps on video) and on caption ranges. Reviewers can
  suggest an edit to a caption, and the team accepts it with one click.
- Threaded replies with resolve and unresolve. Unresolved threads block `approved` unless overridden.
- **Done when**: pins persist and render in the editor, portal and review link, and the
  resolve-gating is tested.

### Step I-8.4 — SLA and reminders (Phase 9) [P1] ✅ Done
- **Implemented via (2026-10-23, MVP Step M-6.5):** Phase 9 complete — `approval_sla_hours`,
  `PostApprovalSlaService`, Home/portal overdue badges, and the daily overdue-approval reminder job.
  See `mvp.md` Step M-6.5 and `home.md` / `notifications.md`.
- Build Phase 9 as written, using the activity log's "entered status at". Overdue items appear on
  Home, the portal and client health (I-6.1).
- **Done when**: the Phase 9 exit criteria are met.

### Step I-8.5 — Client requests and intake [P2]
- Clients can submit content requests and upload assets from the portal ("We're launching X on
  the 12th, here are photos"). A request becomes an `idea` post or a task for the team.
- **Done when**: a submitted request creates an idea post, scoped to the client, with the uploaded
  assets attached (test).

### Step I-8.6 — Mobile-first portal and white-label (Phase 14) [P2]
- Audit every portal page at 375 px (phone width hasn't been checked, per `analytics.md`), with
  swipe-to-approve on mobile and an installable PWA for the portal.
- Build Phase 14's white-label work (logo, colours) and add a custom portal domain per org.
- **Done when**: the portal passes a Playwright mobile viewport suite, and Phase 14's exit criteria
  are met.

---

## Phase I-9 — AI [P1/P2]

### Step I-9.1 — Finish the AI control plane (Steps 2.7–2.9) [P1] ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.1):** `App\Services\AI\AiAccess` is the single gate
  (no controller holds inline AI gating); per-org toggle, token metering, monthly budget and the
  feedback loop all shipped. See `ai.md` › "AI control plane" and dev-plan Steps 2.7–2.9.
- Build the per-org AI toggle, text-generation usage logging into `ai_usage_logs` (images are
  already logged, text isn't), monthly budgets, and feedback capture, as written in the development
  plan. The capability gate lives in a single `AiAccess` service. `AiController::authorizeAiUse`,
  `ClientController::show` (`config('ai.enabled')` plus a role check), and `PostController::edit`
  each repeat that logic inline today.
- **Done when**: Step 2.13's exit criteria for configuration, budget and usage are met, and no
  controller contains AI gating logic.

### Step I-9.2 — Async AI with progress [P1] ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.2):** `ai_text_jobs` + `RunAiTextJob`, `202
  {request_id}` dispatch, poll (`ai-text-jobs.status`) and cancel (`ai-text-jobs.cancel`). See
  `ai.md` › "Async text jobs".
- Captions, trends and ideas run synchronously inside the HTTP request (vendor latency plus one
  schema retry). Move them to queued jobs that return a `request_id`. The UI polls (or uses Reverb,
  I-12.2), shows progress and can be cancelled, so it never hits a gateway timeout.
- **Done when**: a slow provider (faked with a delay) doesn't block the request, and results arrive
  through the poll endpoint (test).

### Step I-9.3 — Guardrails v2 [P2]
- The banned-phrase match is a plain case-insensitive substring check (`ai.md`). Normalize before
  matching (lowercase, strip punctuation and diacritics, simple stemming and plurals), and offer an
  optional semantic check (a cheap model call) for `do_nots`.
- Apply guardrails to the Trends & Ideas panel too (currently editor only).
- **Done when**: plural and reworded variants of a banned phrase are caught (regression tests).

### Step I-9.4 — Writing tools in the editor [P2] ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.3):** `WritingAssistant` + `RewriteCaptionAction`
  (shorten/expand/tone/CTA/adapt), alt-text-from-image, and the existing Repurpose. Translate and
  hashtag research deferred (translate needs M-6.8). See `ai.md` › "Writing tools".
- Inline actions on the selected text or the whole caption: shorten, expand, change tone, adapt to
  {platform} (writes the I-3.1 override), translate (to the client's `default_language`, AI Step
  2.12), add CTA, generate alt text from the image (multimodal), and hashtag research (scored against
  `client_hashtags` usage, I-2.6, and later performance, I-10).
- **Repurpose**: paste a blog URL or long text and get N platform-specific draft posts.
- **Done when**: each tool is behind `AiAccess`, budgeted, logged, guardrailed and tested with a
  faked provider.

### Step I-9.5 — Honest trends [P2] ✅ Done
- **Implemented via (2026-11-10, MVP Step M-7.5):** `TrendSource` contract (`TopPostsTrendSource`
  bound; swappable), trends labelled by source with model ones marked "AI hypothesis", cap lifted to
  `ai.suggestions.per_request` (6). See `ai.md` › "Honest trends".
- DeepSeek can't browse, so "trends" are model hypotheses. Label them that way in the UI. Add real
  signal sources behind a `TrendSource` contract (e.g. Google Trends, TikTok Creative Center, the
  client's own top-performing posts once I-10.1 lands). Lift the cap of 3 per request back to Step
  2.13's 5–10.
- **Done when**: every trend shows its source, and the provider and source are swappable in tests.

### Step I-9.6 — Image Studio polish [P3]
- Aspect-ratio presets per target format (I-3.2), a brand-compliance check on generated images
  (palette/logo), inpainting and outpainting via `refine`, and "use as carousel" (attach N variants in
  order).
- **Done when**: presets come from the capability registry, and carousel attach preserves order.

---

## Phase I-10 — Analytics & reporting [P1]

### Step I-10.1 — Engagement metrics (Phase 12, top priority) [P1] ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.1):** `EngagementSource`, `post_target_metrics`, `social_account_metrics`, `EngagementMetrics`; see `analytics.md`.
- Without engagement data the product can't prove ROI, which is the main reason agencies keep or
  drop a tool. Build Phase 12 now. Fill `engagement` in the shared analytics services (Phase 17
  already left the slot) and the platform analytics.
- Add account-level metrics too (`social_account_metrics`: followers, reach, profile views per day),
  so follower growth can be charted.
- **Done when**: Phase 12's exit criteria are met, and the org analytics and client drill-down show
  engagement and follower growth.

### Step I-10.2 — Insights from data [P2] ✅ Done (best time + top posts)
- **Implemented via (21 Oct 2026, MVP Step M-3.2):** `BestTimeService`, top posts; pillar / format / hashtag breakdowns and the queue (I-3.7) hook still open.
- Best time to post per account, from real engagement by hour and weekday in the account's timezone.
  This replaces the heuristic planned in AI Step 2.10, and feeds the queue slots (I-3.7).
- Top posts, a content pillar performance breakdown (needs posts tagged with a pillar: add
  `posts.pillar`), format performance (reel vs carousel), and hashtag performance.
- **Done when**: each insight has a props test on seeded metrics, and best-time output feeds the
  queue.

### Step I-10.3 — Client-facing reports (Phase 8) [P1] ✅ Done
- **Implemented via (21 Oct 2026, MVP Step M-3.3):** `client_reports`, monthly schedule, portal + emailed PDF; see `analytics.md`.
- Build Phase 8 on top of Phase 17's services: a branded monthly report (portal page plus emailed
  PDF) with engagement, posts shipped, approvals turnaround, top posts and an editable agency
  commentary section. Scheduled per client.
- **Done when**: a client reviewer sees only their own report, with no billing data (isolation test).

### Step I-10.4 — Link tracking [P2]
- A UTM builder per post or target, an optional short link with click tracking, and a link-in-bio page
  per client (Later and Linktree-style). Click data feeds analytics.
- **Done when**: short-link redirects are logged, attributed to the post and target, and appear in
  analytics.

---

## Phase I-11 — Billing [P2]

### Step I-11.1 — Invoicing completeness [P2] ✅ Partially done via M-4.1 (24 Oct 2026)
- **Implemented via M-4.1** (`mvp.md`): tax/VAT lines (per item + per invoice, per-org rate list),
  VAT/TRN numbers, and a per-client billing currency with the mixed-currency retainer rejected. The
  dashboard revenue chart stays org-default-currency (unchanged). **Still open (deferred to M-12):**
  discounts, partial payments (`invoice_payments`), credit notes, and configurable number formats.
- Tax/VAT lines (per item or per invoice, with a rate), discounts, partial payments
  (`invoice_payments` table and outstanding balance), credit notes (formalize "void"), and
  configurable invoice number formats.
- A per-client billing currency. Today the retainer has no currency of its own and is added as-is to
  an invoice in any currency (`billing.md`).
- A dashboard revenue chart per currency. Non-default currencies are currently ignored.
- **Done when**: tax and partial payments are reflected in the PDF, ledger and CSV, and the mixed
  currency retainer case is rejected or converted, with tests.

### Step I-11.2 — Automation and getting paid [P2]
- **Recurring retainer invoices bullet implemented via M-4.3** (`mvp.md`, 24 Oct 2026):
  `GenerateRetainerDraftsAction` + `invoices:generate-retainer-drafts` drafts the period's retainer
  for opted-in clients (`clients.auto_invoice_retainer`), idempotent per `(client_id, retainer_period)`,
  drafts only. The M-4.2 handshake covers "getting paid" without online payments. Online card
  payment, opt-in auto-reminders and accounting sync remain for M-12.
- Recurring retainer invoices generated per `billing_cycle` as drafts for review (opt-in auto-send).
- Opt-in automatic overdue reminders (currently manual by design; keep that as the default).
- Online payment (**post-MVP, not in the MVP**): a Stripe payment link per invoice with a webhook to
  mark it paid, and a "Pay now" button in the portal. The MVP instead ships the manual mark-paid /
  confirm-payment handshake in **Step M-4.2** of `mvp.md` (client reports payment → org confirms;
  Graphy never processes money), so this bullet is only the later add of *actually* taking card
  payment through the app, layered on top of that handshake rather than replacing it.
- Accounting sync behind an `AccountingProvider` contract (Xero first, then QuickBooks).
- Link invoice items to posts from the UI (`post_id` exists, with no UI).
- **Done when**: a recurring draft is generated on schedule, and a Stripe webhook marks an invoice
  paid idempotently (tests).

### Step I-11.3 — SaaS subscription billing for Graphy itself [P2]
- Organizations sign up for free today, and "revenue" in super admin is GMV billed through the app
  (`super-admin.md`). Add plans (Laravel Cashier + Stripe) with limits (clients, seats, social
  accounts, AI budget), a trial, dunning, and plan gating via Laravel Pennant feature flags. Super
  admin shows real MRR and churn.
- **Done when**: plan limits are enforced in Actions (tests per limit), and super admin MRR comes
  from subscriptions.

---

## Phase I-12 — Team, notifications, collaboration [P1/P2]

### Step I-12.1 — Notification preferences and channels [P1] ✅ Done (chat = WhatsApp later, not Slack)
- **Implemented via (2026-10-23, MVP Step M-6.7):** per-user preferences (in-app + digest per
  category), a daily digest, a paginated notification centre with "load more" + unread filter, and a
  swappable `ChatNotifier` contract (`NullChatNotifier` bound). The chat channel is deferred to a
  **WhatsApp** integration (not Slack, per Alex). See `notifications.md` › Phase M-6.7.
- Notifications are database-only, broadcast to every org user, capped at 50, and synchronous
  (`notifications.md`). Add:
  - per-user preferences per event type × channel (in-app, email, digest), with sensible defaults;
  - a daily digest email;
  - queued notifications (I-1.4);
  - a paginated notification centre with "load more" and filters;
  - Slack/Microsoft Teams channel per org, or per client, behind a `ChatNotifier` contract.
- **Done when**: preferences are honoured per channel (tests), and Slack delivery is tested with
  `Http::fake()`.

### Step I-12.2 — Real-time [P2]
- Laravel Reverb for live notification badges, live presence ("Alex is editing this post", with a
  soft lock to avoid overwrites), and live AI/job progress (I-9.2).
- **Done when**: two sessions see each other's presence, and a stale-write conflict is detected with
  `updated_at` optimistic locking on `UpdatePostAction` (tested).

### Step I-12.3 — Team structure [P2]
- Client-scoped staff: restrict a Strategist or Designer to their assigned clients
  (`client_user` pivot), enforced in policies and the I-1.9 global scope. Bigger agencies need this.
- Multi-org membership with an org switcher (freelancers working for several agencies) via a
  `organization_user` pivot, replacing the single `users.org_id`. Plan the migration carefully and
  record it in `organizations-users.md`.
- Self-service "leave organization" (`staff.md` gap). An org policy to require 2FA.
- Google/Microsoft SSO sign-in (Socialite, listed in the original stack but not installed).
- **Done when**: a client-scoped Strategist can't see unassigned clients anywhere (generated test),
  and the org switcher keeps everything scoped correctly.

### Step I-12.4 — Org-wide audit log [P2]
- Only posts and staff have activity logs. Add an `audit_logs` table (actor, org, subject,
  action, before/after diff, IP, user agent) for clients, Brand Brain, accounts, invoices, settings,
  key changes (never the key value) and exports. Owner-only viewer with filters and CSV export.
  Super admin gets the platform equivalent (`super-admin.md` gap).
- **Done when**: every write Action in those modules records one audit row (a test per module), and
  no secret value is ever stored in a diff.

---

## Phase I-13 — Platform, onboarding, super admin [P2]

### Step I-13.1 — First-run onboarding [P1]
- After signup the Owner lands on an empty `/dashboard`. Add a guided setup: org basics → first
  client → Brand Brain quick-start (or AI draft from website, I-6.4) → connect an account →
  first post. Offer optional sample data (cloned from the preview fixture into the new org, clearly
  labelled and one-click removable).
- **Done when**: completing the wizard produces a schedulable post, and the sample-data removal
  deletes only the sample rows (test).

### Step I-13.2 — Super admin operations [P2]
- Impersonation ("view as"), with a mandatory reason, a time limit, a persistent banner and an audit
  entry (I-12.4). Suspension emails to the owner. A super admin management UI (invite and remove).
  A queue/scheduler/vendor health dashboard (I-1.8). Per-org feature flags (Pennant).
- **Done when**: impersonation is audited and time-boxed (tests), and suspension notifies the owner.

### Step I-13.3 — Public API & webhooks (Phase 13) [P2]
- Build Phase 13 with Sanctum, and extend the events to `post.published`, `post.failed`,
  `post.approved` and `client.health_changed`. Publish an OpenAPI spec and add a Zapier/Make app.
- **Done when**: Phase 13's exit criteria are met, and the OpenAPI spec is generated and checked in
  CI.

---

## Phase I-14 — UI/UX & frontend architecture [P1/P2]

### Step I-14.1 — Break up the god components [P1]
- Files that are too large to maintain or review: `pages/clients/show.tsx` (2,639 lines; I-2.4 splits
  it), `pages/invoices/show.tsx` (1,048), `components/analytics/organization-analytics-view.tsx`
  (1,034), `pages/posts/edit.tsx` (908; I-4.6), `pages/dashboard.tsx` (855),
  `components/image-studio/image-studio.tsx` (824), `pages/super-admin/organizations/show.tsx` (766),
  `components/content-calendar.tsx` (744).
- Use feature folders (`resources/js/features/{posts,clients,invoices,…}`) holding components, hooks
  and types. Pages become thin composition.
- **Done when**: no page or component file is over ~400 lines (enforced by a lint rule or CI script).

### Step I-14.2 — Missing primitives [P1] ✅ Done
- **Implemented via (20 Oct 2026):** Step M-1.4 in `mvp.md` — see `frontend.md`.
- `components/ui` has no data table, popover, combobox, date picker, calendar, command (the command
  palette is custom), hover card, progress, scroll area or toast queue variants. Add shadcn
  `table` + TanStack Table (a shared `DataTable` with sorting, pagination, column visibility, row
  selection and bulk actions, used by clients, invoices, posts, staff, super admin orgs and reports),
  plus `popover`, `command`, `calendar`, `date-picker`, `combobox` and `progress`.
- Replace the hand-written `fetch()` calls (notification bell, asset library, music field, client page
  connect flow, editor) with a single `http` helper (CSRF, JSON, errors to toast) or Inertia v3's HTTP
  utilities.
- **Done when**: every list page uses `DataTable`, and no raw `fetch(` remains outside the helper
  (grep check in CI).

### Step I-14.3 — State, feedback and resilience [P2]
- Keep tab, filter and pagination state in the URL everywhere (shareable, back-button safe).
- Consistent empty, loading (Skeleton exists) and error states per brand guidelines §6, plus
  optimistic UI for transitions, comments and notification reads, rolled back on failure.
- A global error boundary with a friendly fallback and a "report" action wired to error tracking
  (I-16.1).
- **Done when**: each list and detail page has all three states (visual check plus RTL tests on the
  shared components).

### Step I-14.4 — Accessibility [P1]
- Only about 60 `aria-*` attributes exist across all pages. Run an axe audit on every page (in
  Playwright, I-14.6), cover keyboard paths for drag-and-drop (I-5.1), focus management in dialogs and
  sheets, visible focus rings, colour contrast for status tones in both themes, labelled icon
  buttons, and live regions for toasts and async results.
- **Done when**: zero serious or critical axe violations across the Playwright page suite, in light
  and dark mode.

### Step I-14.5 — Responsive and dark-mode verification [P1]
- Several module docs record that dark mode and phone width were never checked. Run a visual pass
  at 375 / 768 / 1280 px in both themes for every page, and fix overflow (tables, calendar, editor
  side panels).
- **Done when**: Playwright screenshot tests exist for the key pages at the three widths in both
  themes.

### Step I-14.6 — Frontend tests [P1] 🟡 Partly done
- **Implemented via (20 Oct 2026):** Step M-1.5 in `mvp.md` — Vitest + RTL and the *sign up → first post scheduled* journey run in CI; the other three journeys land with M-4 / M-6 / M-11.
- There are no frontend tests. Add Vitest + React Testing Library for components and hooks
  (`lib/post-workflow.ts`, the checklist rendering, the timezone helpers), and Playwright E2E for the
  critical journeys: sign up → first post scheduled, client approves via portal, publish failure →
  retry, invoice → send → paid. Run both in CI (`.github/workflows/tests.yml`).
- **Done when**: CI runs Vitest and Playwright on every PR, and the four journeys are green.

### Step I-14.7 — Internationalization groundwork [P3]
- No i18n exists. Move UI strings into translation files (`laravel-react-i18n` or similar), make
  date, number and currency formatting locale-aware, and keep English as the only shipped locale at
  first. This unblocks selling outside English-speaking markets later.
- **Done when**: a pseudo-locale build shows no hard-coded strings on the core pages.

### Step I-14.8 — Performance budget [P2]
- Code-split heavy chunks (Recharts, the image studio, the calendar, the editor) with `React.lazy`.
  Add a bundle-size check in CI, and preload the routes users are likely to visit next.
- **Done when**: the initial JS for `/dashboard` stays under the agreed budget, enforced in CI.

---

## Phase I-15 — Integrations [P2]

### Step I-15.1 — Inbox (Phase 3) and engagement [P2] ✅ Done (comments; DMs deferred)
- **Implemented via MVP Phase M-9 (2026-11-12):** unified comments inbox at `/inbox` with assignment,
  saved replies, deterministic sentiment tags, a reply SLA + daily reminder, and AI draft replies that a
  human always sends (`CommentSource` / `ReplyAssistant` seams, `SendInboxReplyAction` the only vendor-reply
  path). Seven networks (M-0.2); Pinterest/Google Business and DMs deferred behind the same contract. See
  `.junie/modules/inbox.md`.
- Unified comments inbox (Phase 3), plus DMs where the vendor allows. Assignment, saved replies, AI
  draft replies (human sends), sentiment tags, and SLA timers. Competitors treat this as a core
  feature.
- **Done when**: ✅ Phase 3's steps are complete, and replies require an explicit human send (test).

### Step I-15.2 — Workspace integrations [P3]
- **Partly done (23 Oct 2026, MVP Step M-5.9):** Canva/Drive/Dropbox imports each sit behind their
  own `App\Contracts\DesignImportSource` with a faked implementation in tests, as this step requires.
  Google Calendar/Outlook, Slack/Teams and Zapier/Make remain.
- Google Calendar/Outlook sync (from the iCal feed in I-5.3, upgraded to two-way for key dates),
  Canva/Drive/Dropbox (I-7.2), Slack/Teams (I-12.1), Zapier/Make (I-13.3).
- **Done when**: each integration sits behind its own contract with a faked implementation in tests.

---

## Phase I-16 — Engineering quality, observability, ops [P1]

### Step I-16.1 — Observability [P1] ✅ Done
- **Implemented via (20 Oct 2026):** Step M-1.1 in `mvp.md` — see `ops.md`.
- Error tracking (Sentry or Flare) with user, org and request context, and secrets scrubbed.
- Structured JSON logs in production with a request/correlation id carried into queued jobs (via job
  middleware), so one publish can be traced across request → job → webhook.
- Laravel Pulse for slow queries, slow jobs and slow outgoing requests. Alert on failed jobs in the
  `publishing` queue and on webhook signature failures.
- **Done when**: a forced publish failure shows up in error tracking with the post id and correlation
  id, and an alert fires.

### Step I-16.2 — Security hardening [P1] ✅ Done
- **Implemented via (20 Oct 2026):** Step M-1.2 in `mvp.md` — see `ops.md`, `assets.md`.
- Security headers middleware (CSP with nonces for Vite, HSTS, `X-Frame-Options`/`frame-ancestors`,
  `Referrer-Policy`, `Permissions-Policy`).
- `composer audit` and `npm audit` in CI. Session cookie `secure`/`same_site` set for production.
- Password-confirmation for sensitive Owner actions (key changes, exports, purge).
- Upload hardening: MIME sniffing (not just the extension), SVG sanitization or SVG blocking, and a
  virus scan hook on uploads.
- **Done when**: a header test asserts the policy on every response, and CI fails on high-severity
  advisories.

### Step I-16.3 — Data protection [P2]
- GDPR tooling: export and delete for a client contact or user, retention policies (activity logs,
  AI usage logs, notifications, discarded AI images; `ai-images:prune` exists for the last), and a
  DPA-ready record of processors (Upload-Post, DeepSeek, Gemini, Resend).
- **Done when**: exporting and deleting a contact is tested end to end, and retention jobs are
  scheduled with overlap protection.

### Step I-16.4 — Code-level cleanups [P2]
- Controllers that still carry logic or presentation: `ClientController` (seven transformers),
  `AiController` (inline gating, I-9.1), `CalendarController` (filter-option queries), and
  `FacebookInstagramConnectController` (221 lines). Move the logic into Actions, presenters and
  Form Requests (AGENTS.md rule 1). Introduce Form Requests for filter-only `GET`s (calendar,
  analytics already has one).
- Remove the default-constructed dependencies in Action signatures
  (`TransitionPostStatusAction::__invoke(..., EvaluatePostChecklistAction $x = new ...)`,
  `PublishPostJob::handle(..., PostNotificationService $n = new ...)`). Inject through the
  constructor so the container can swap them (AGENTS.md rule 4).
- Fix the known PHPStan `argument.type` report in `OrganizationController@update` (`billing.md`).
- Freeze time in `GenerateDailyBriefsActionTest` so it stops failing inside quiet hours (`ai.md`).
- CI runs PHP 8.3 while local development uses Herd's PHP 8.5. Add 8.5 to the CI matrix and pin the
  minimum in `composer.json`.
- **Done when**: PHPStan level 7 is clean with no baseline entries for these, the daily-brief test is
  deterministic, and CI covers both PHP versions.

### Step I-16.5 — Documentation drift [P3] ✅ Done
- **Implemented via (19 Oct 2026, Step M-0.3 in `mvp.md`):** README statuses fixed, `review-portal.md` / `inbox.md` rows removed, the settings keys note corrected, Pest → PHPUnit and the database references → MySQL in the plan and module docs, and `tests/Unit/ModuleDocsTest.php` as the CI doc check.
- `.junie/modules/README.md` lists `review-portal.md` as "Planned" (the portal is built and
  documented in `posts.md`), `inbox.md` (doesn't exist), and `publishing.md` as "vendor pending"
  (it's live). `settings.md` says keys "aren't consumed by anything yet" (they are). The module
  template says "Pest files" while the suite is PHPUnit, and the development plan's stack table still
  says Pest. Fix all of these, and add a CI check that every module file listed in the README
  exists.
- **Done when**: README statuses match reality, and the CI doc check passes.

---

## Sequencing

| Wave | Focus | Steps |
|---|---|---|
| 1 (now) | Stop the bleeding | I-1.1 → I-1.9, I-2.1, I-2.8 (Redis is a prerequisite for `onOneServer`), I-16.1 |
| 2 | Speed at scale | I-2.2 – I-2.6, I-14.2, I-14.6 (tests before the big refactors) |
| 3 | Core loop excellence | I-3.1 – I-3.3, I-3.5, I-3.6, I-3.8, I-4.1 – I-4.4, I-8.1, I-8.2, I-8.4 (Phase 9), I-12.1 |
| 4 | Prove results | I-10.1 (Phase 12), I-10.3 (Phase 8), I-6.1, I-6.2, I-9.1, I-9.2 |
| 5 | Competitive depth | I-3.4, I-3.7, I-5.x, I-7.x, I-4.5 – I-4.7, I-8.3, I-8.5, I-9.3 – I-9.5, I-10.2, I-10.4 |
| 6 | Scale the business | I-11.x, I-12.2 – I-12.4, I-13.x (incl. Phase 13), I-8.6 (Phase 14), I-15.x |
| Ongoing | Quality | I-14.1, I-14.3 – I-14.5, I-14.7, I-14.8, I-16.2 – I-16.5 |

## Exit criteria for this plan

- No P0 finding from the audit summary remains open, and each has a regression test.
- Home, calendar, client pages and the content hub stay within the I-2 budgets on a 50-client,
  20k-post seeded dataset.
- A post can be tailored per platform, published per target with automatic retry, partially
  recovered, and cancelled or edited after scheduling without duplicates.
- A client contact approves from a magic link on a phone, sees exactly what will publish, and
  receives a monthly branded report with real engagement numbers.
- PHPStan level 7, Pint, PHPUnit, Vitest, Playwright (including axe), `tsc` and `vp check` are green
  in CI, and every touched module's `.junie/modules/*.md` reflects the change.
