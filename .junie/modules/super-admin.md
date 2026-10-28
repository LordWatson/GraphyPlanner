# Super Admin (platform console)

## Purpose
A platform-level area for Graphy/Alima staff who don't belong to any organization: onboard new
organizations (the first real "create an organization" path — orgs used to exist only via seeders),
manage any organization (view/update/suspend/reinstate/delete), and view, save, re-run and export
cross-org analytics. Covers Phase 15 (Steps 15.1–15.6) of the plan.

## Models / Tables
- `App\Enums\Role::SuperAdmin` (`super_admin`): org-less platform staff — `users.org_id` and
  `client_id` stay `null`. `User::isSuperAdmin()`. Factory state: `User::factory()->superAdmin()`.
- `organizations` (Step 15.3 migration `2026_10_17_120000`): `suspended_at` (nullable timestamp),
  `suspension_reason` (nullable text, shown to locked-out members), `suspended_by` (nullable FK →
  `users`, `nullOnDelete`). `Organization::isSuspended()`, scopes `active()` / `suspended()` /
  `excludingPreview()`, `owner()` (earliest Owner, `HasOne` + `oldestOfMany`), `posts()`,
  `invoices()`, `suspendedBy()`, `PREVIEW_SLUG` + `isPreviewFixture()`. Factory state `suspended()`.
- `SuperAdminReport` (`super_admin_reports`, migration `2026_10_17_120100`): `name`, `description`,
  `filters` (JSON — `range`, `from`, `to`, `org_ids`, `metrics`), `created_by` (FK → `users`,
  `nullOnDelete`), `last_run_at`. Platform-level, so no `org_id`. Factory: `SuperAdminReportFactory`.
- Migration `2026_10_17_120200` grandfathers every existing user as email-verified (see Business
  Rules → email verification).

## Actions / Services / Contracts
- `App\Actions\Organizations\RegisterOrganizationAction` (Step 15.2.1): creates an `Organization`
  (unique slug, never `preview-studio`) and its first `Role::Owner` user in one `DB::transaction()`.
  Self-serve (password given) → fires `Registered` (Fortify email verification). Onboarded by a
  super admin (no password) → random password, email marked verified, and a password-reset link.
  Either way queues `App\Mail\OrganizationWelcomeMail` after commit and logs `org_id`, `user_id`,
  `owner_email`, `source`, `onboarded_by` (never the password).
- `App\Actions\SuperAdmin\ListOrganizationsAction`: paginated (15) directory; search on org name/slug
  or any member's name/email; `status` (`all|active|suspended`); `sort`
  (`newest|oldest|name|clients|published`); eager-loads `owner`, counts users/clients/published posts.
- `GetOrganizationDetailAction`: detail page payload (details, suspension, owner, roster, latest 12
  clients, counts, last published at).
- `UpdateOrganizationAction`: name, slug, timezone, currency, monthly AI image quota (keys and the
  invoicing profile stay owner-managed). Logs changed fields.
- `SuspendOrganizationAction` / `ReinstateOrganizationAction`: set/clear `suspended_at`,
  `suspension_reason`, `suspended_by`; idempotent; refuse the preview fixture.
- `DeleteOrganizationAction`: one transaction — deletes the org's members (plus their notifications
  and sessions) explicitly, then the org (everything org-scoped cascades). Refuses the preview fixture.
- `GetPlatformAnalyticsSummaryAction` (Step 15.4): the platform aggregation layer, composed from the shared analytics core (Step 17.1, see `analytics.md`). Takes
  `AnalyticsFilters`, returns `filters`, `metrics`, sections `organizations`, `users`, `clients`,
  `posts`, `revenue`, `ai` (unselected = `null`), `engagement` (always `null` until Phase 12),
  `primary_currency`, `series` (per day ≤ ~2 months, else per month: signups/published/failed/
  invoiced) and `top_organizations` (top 10 by published).
- `GetSuperAdminOverviewAction`: landing page — 30-day summary, 5 newest orgs, 5 suspended, 5 reports.
- `CreateReportAction` / `UpdateReportAction` / `DeleteReportAction` / `RunReportAction` (Step 15.5).
  `RunReportAction` passes the stored filters straight to `GetPlatformAnalyticsSummaryAction` (no
  duplicated aggregation) and stamps `last_run_at`.
- `App\Support\SuperAdmin\AnalyticsFilters`: the one filter value object (query string, drill-down,
  saved reports). Ranges `7d|30d|90d|12m|ytd|custom` (UTC); unknown input falls back to `30d`;
  `toStoredArray()` keeps presets relative (no dates) and custom ranges pinned.
- `App\Support\SuperAdmin\PlatformAnalyticsCsv`: flattens a summary into CSV rows (selected sections,
  per-currency money rows, the series, top organizations).
- Policies: `OrganizationPolicy` gains `viewAny/view/create/suspend/delete` (super admin only);
  new `SuperAdminReportPolicy`. `Gate::before` in `AppServiceProvider` grants super admins every
  ability (they bypass per-org policies, but `org.member` keeps them out of org workspaces anyway).

## Middleware
- `EnsureUserIsSuperAdmin` (alias `super_admin`): 403 unless `Role::SuperAdmin`.
- `EnsureUserHasOrganization` (alias `org.member`): on the org workspace (main staff group, client
  portal, `settings/organization`, `settings/ai-usage`). Super admins → redirect to
  `/super-admin`; any other org-less account → 403.
- `EnsureOrganizationNotSuspended`: appended to the `web` group (after `HandleInertiaRequests`).
  A member of a suspended org gets the `organizations/suspended` Inertia page with status 403 on
  every route except `logout`; JSON requests get a plain 403. Support address:
  `config('mail.support_address')` (`MAIL_SUPPORT_ADDRESS`), falling back to the from address.

## Phase M-1 additions
- Deleting an organization also requires the super admin's current password
  (`DeleteOrganizationRequest`).
- `reports/{report}/export` is behind `password.confirm`.
- The organizations and reports lists render through the shared `DataTable`.
- `/pulse` (Laravel Pulse) is super-admin only, like `/horizon` (see `ops.md`).

## Phase M-8 — Design-partner release
- **`organizations.is_design_partner`** (boolean, default false, indexed; migration
  `2026_11_11_100100`): set by a super admin on the (up to five) orgs on the design-partner offer.
  `Organization::isDesignPartner()`, scope `designPartners()`, factory state `designPartner()`,
  fillable + boolean cast. Editable on the org detail form (`UpdateOrganizationAction` /
  `UpdateOrganizationRequest` gained the field; `GetOrganizationDetailAction` exposes it; a lime
  "Design partner" chip shows in the detail header). M-10 will auto-apply the design-partner coupon
  to these orgs.
- **Partner usage view** (`super-admin.partners.index` → `SuperAdmin\PartnerController@index`,
  `super-admin/partners/index.tsx`): one row per design-partner org with **weekly active users**
  (distinct `users.last_active_at` in the last 7 days — always a 7-day window), **posts published**,
  **client approvals** and **internal approvals** (reported separately, with a total), **invoices
  sent** and **AI calls** over the selected range (`?range=7d|30d|90d`, default 30d).
  `GetPartnerUsageAction` reuses the Phase 17 analytics core (`PostActivityMetrics`,
  `ApprovalMetrics::approvalsBySource()`, `RevenueMetrics`, `AiUsageMetrics`) scoped to one org, with
  WAU as a single grouped query — there are ≤5 partners, so a per-org pass is cheap. The page also
  surfaces the latest 6 feedback notes.
- **Approval split** (`ApprovalMetrics::approvalsBySource()`): reads `post_approvals` (the decision
  record, so unscheduling never counts) and classifies each `Approved` decision as **client** (a
  `Role::ClientReviewer`, or the unauthenticated review portal with a null actor — matching
  `TransitionPostStatusAction`'s own "client decision" rule) vs **internal** (an Owner override or an
  internal approve). One grouped query joined to `users` for the actor's role.
- **Feedback inbox** (`super-admin.feedback.index` → `PartnerController@feedback`,
  `super-admin/feedback/index.tsx`): every `product_feedback` note, newest first, with an org
  filter. `ListProductFeedbackAction` (paginated 20; `recent()` for the partners panel;
  `row()` shared mapper). Super admins are org-less so the `OrganizationScope` doesn't filter here.
- **Staff "Send feedback" panel** (cross-cutting): `ProductFeedback` (`product_feedback`, migration
  `2026_11_11_100300`: `org_id` cascade, `user_id` nullOnDelete, `page`, `message`), written by
  `App\Actions\SubmitProductFeedbackAction` (one transaction: create + notify super admins), posted
  from `POST /feedback` (`ProductFeedbackController@store`, `StoreProductFeedbackRequest` — internal
  staff only, client reviewers/super admins can't reach it) via `components/feedback-dialog.tsx` in
  the studio sidebar footer (captures the current page URL). Every super admin gets a
  `App\Notifications\ProductFeedbackReceived` (database) linking to the inbox. See also
  `organizations-users.md` (`last_active_at`, `TrackUserActivity`) and `notifications.md`.
- **Console book**: `platformChapters.partners` (05; `/super-admin/partners` + `/super-admin/feedback`),
  `platformSettings` bumped to 06.

## Routes / Controllers
Public (guest):
- `GET|POST /register-organization` → `Organizations\RegistrationController@create/store`
  (`organizations.register[.store]`, POST throttled 6/min). `/register` (named `register`) redirects
  here — Fortify's own registration feature is disabled.

Super admin (`auth`, `verified`, `super_admin`, prefix `super-admin`, names `super-admin.*`):
- `GET /` → `SuperAdmin\DashboardController` (`dashboard`).
- `GET organizations`, `GET organizations/create`, `POST organizations`,
  `GET|PUT|DELETE organizations/{organization}`, `POST organizations/{organization}/suspend`,
  `POST organizations/{organization}/reinstate`, `GET organizations/{organization}/analytics`
  → `SuperAdmin\OrganizationController` (the drill-down renders the org's own analytics since Step 17.9).
- `GET analytics` → `SuperAdmin\AnalyticsController@index`.
- `Route::resource('reports')` + `GET reports/{report}/export` → `SuperAdmin\ReportController`.

Form Requests: `OrganizationRegistrationRequest`, `SuperAdmin\{StoreOrganizationRequest,
UpdateOrganizationRequest, SuspendOrganizationRequest, DeleteOrganizationRequest,
OrganizationIndexRequest, AnalyticsFilterRequest, SaveReportRequest}` — each exposes a typed
accessor (`registration()`, `details()`, `report()`, `filters()`) for its Action.

Frontend (`resources/js`): pages `organizations/{register,suspended}`,
`super-admin/dashboard`, `super-admin/organizations/{index,create,show,analytics}`,
`super-admin/analytics/index`, `super-admin/reports/{index,edit,show}`; shared components in
`components/super-admin/` (`types.ts`, `analytics-filter-bar.tsx`, `platform-analytics-view.tsx`).
The studio shell is reused: `AppSidebar` and the ⌘K palette switch to the console's own book
(`platformChapters` in `lib/chapters.ts`: 01 Overview · 02 Organizations · 03 Analytics · 04 Reports ·
05 Settings) for `super_admin`; `useChapter()` resolves `/settings` to the console book for them.
New status kind `organization` (`active`/`suspended`/`preview`) in `lib/status.ts`.

## Business Rules
- **Login:** Fortify flow unchanged; `LoginResponse`/`TwoFactorLoginResponse` send super admins to
  `/super-admin` (an intended URL still wins, and `org.member` bounces any org URL to the console).
- **Signup:** org + Owner are created together or not at all; email unique across `users`
  (lowercased); the new owner is signed in and sent to `/dashboard`, where `verified` sends them to
  the verification notice first.
- **Email verification:** `User implements MustVerifyEmail`. Invitation accepts (staff/client) mark
  the address verified (the emailed link proves the inbox); super-admin onboarding marks it verified
  (the set-password link is the only way in); pre-existing users were grandfathered by migration.
- **Suspension** is soft and reversible: members are locked out, nothing is deleted; scheduled work
  pauses — `PublishPostJob` skips (post stays `scheduled`), `SendUpcomingPostReminderEmailsAction`
  and `GenerateDailyBriefsAction` exclude suspended orgs.
- **Delete** requires typing the org name (case/space-insensitive) and removes members too.
- **Preview fixture** (`slug = preview-studio`): can't be suspended, deleted, or have its slug taken
  or changed; excluded from platform analytics unless explicitly selected.
- **Analytics:** published/failed counted from `post_activity_logs` transitions; money never summed
  across currencies (per-currency lists; series in the range's `primary_currency`); revenue =
  what orgs invoiced their own clients (sent + paid; draft/void never counted) — platform GMV, not
  subscription revenue; the "30-day run rate" is billed in the 30 days to the range end; AI cost is
  the `ai_usage_logs.cost_estimate` sum (USD). Super admins are never counted as users.

## Tests
- `tests/Feature/SuperAdmin/SuperAdminAccessTest.php`: every super-admin page reachable by a super
  admin, 403 for Owner/Strategist/Designer/Viewer (pages and write endpoints), guest redirect, login
  redirect (super admin → `/super-admin`, owner → `/dashboard`), the seeded super admin end-to-end,
  org-workspace redirects, super admin keeps profile/appearance/notifications, org-less users 403.
- `tests/Feature/OrganizationRegistrationTest.php`: render, `/register` redirect, org + owner created
  together and signed in, verification notification + welcome mail queued, unverified owner sent to
  the notice, duplicate (case-insensitive) email rejected with no partial org, validation, slug
  uniqueness (incl. never `preview-studio`), guest-only.
- `tests/Feature/SuperAdmin/OrganizationManagementTest.php`: list/search (name and member email),
  status filter, detail payload (no secrets), update + slug rules, suspend → members get the support
  page (logout still works, super admin unaffected), reinstate, delete confirmation, cascade +
  member removal, preview fixture protection, onboarding (verified owner, set-password welcome mail),
  duplicate-email onboarding rejected.
- `tests/Feature/SuperAdmin/OrganizationSuspensionTest.php`: publish job / reminders / daily briefs
  skip suspended orgs, portal contacts locked out, invite accept marks email verified.
- `tests/Feature/SuperAdmin/PlatformAnalyticsTest.php`: multi-org fixtures → exact platform totals,
  series sums, per-org drill-down, custom range + org filter, monthly bucketing, metric selection,
  validation, overview payload.
- `tests/Feature/SuperAdmin/ReportControllerTest.php`: create (relative vs custom storage), validation,
  update, delete, run ≡ direct action call (+ `last_run_at`), run view, index org names, builder
  prefill, CSV export.
- `tests/Unit/Support/AnalyticsFiltersTest.php`: range resolution, fallbacks, sanitising, storage.

## Known Gaps / Open Questions
- Engagement metrics wait on Phase 12 (`PostTargetMetrics`); `engagement` is always `null`.
- No impersonation ("sign in as this org") — deliberately left out pending an audit-trail design.
- No general super-admin audit log beyond `suspended_by` and structured `Log::` entries.
- Managing super admins themselves (invite/remove) has no UI; use `SuperAdminUserSeeder`/tinker.
- Suspending doesn't email the org's owner. (Resolved 2026-10-18, Step I-1.5: deleting now removes
  stored files — see Changelog.)
- Platform subscription billing doesn't exist, so "revenue" is GMV billed through the app.
- Platform analytics still buckets in PHP and uses `whereIn` org-id lists (Step I-2.7 converted the
  organization summary only); `SeriesBuckets::countByBucketSql()` is ready to reuse here.
- Password-reset tokens expire after `auth.passwords.users.expire` (60 min), so an onboarded owner
  may need to request a fresh link — the welcome email explains how.

## Changelog
- 2026-10-24: Phase M-8 (design-partner release) — `organizations.is_design_partner`, the partner
  usage view (`GetPartnerUsageAction`, WAU via `users.last_active_at`/`TrackUserActivity`) and the
  in-app feedback capture (`product_feedback`, `SubmitProductFeedbackAction`, staff
  `FeedbackDialog`, super-admin feedback inbox + `ProductFeedbackReceived` notification). New console
  chapter 05 "Partners". Tests: `PartnerUsageTest`, `tests/Feature/ProductFeedbackTest`,
  `tests/Feature/UserActivityTrackingTest`, `SuperAdminAccessTest` (partners/feedback pages). See the
  "Phase M-8" section above. S3 and the M-8.3 decision gate (beyond recording Inbox = go) are out of
  scope for this change.
- 2026-10-18: Step I-2.8 — Laravel Horizon installed; `/horizon` is gated to super admins in every
  environment (`App\Providers\HorizonServiceProvider`, `viewHorizon` gate). See `ops.md`.
- 2026-10-18: Step I-1.8 — "System health" panel on the super admin dashboard
  (`GetSuperAdminOverviewAction` → `health`): one row per scheduled command from
  `App\Support\Scheduling\SchedulerHeartbeat::COMMANDS` with its last successful run and a
  Running/Stale pill (stale = no run within two of its intervals, or never), plus the queue
  backlog (pending jobs on the database driver, failed jobs). Every `Schedule::command()` in
  `routes/console.php` now goes through one helper adding `withoutOverlapping()`, `onOneServer()`
  (needs a shared cache store with locks — database/redis) and an `onSuccess` heartbeat. Tests:
  `tests/Feature/Scheduling/SchedulerSafetyTest.php` (introspects `Schedule::events()`).
- 2026-10-18: Step I-1.5 — `Organization` uses `SoftDeletes`. `DeleteOrganizationAction` still
  removes the members, then soft-deletes the org and dispatches `App\Jobs\PurgeOrganizationJob`
  after commit, which deletes every stored file its clients owned (`StoredFileInventory` →
  `AssetStorage`) and then hard-deletes the org (cascades). Idempotent; skips a restored org.
- 2026-10-18: Phase 17 — the analytics core (range resolution, bucketing, post/revenue/AI queries) moved to `App\Support\Analytics` / `App\Services\Analytics` (`AnalyticsFilters` composes an `AnalyticsRange`; `PlatformAnalyticsCsv` maps through the shared `AnalyticsCsv`; filter bar and view rebuilt on `components/analytics`). Platform output unchanged except that note rows (`from_status = to_status`) no longer count as transitions. The per-org drill-down now runs `GetOrganizationAnalyticsSummaryAction` (every section, org timezone) — see `analytics.md`.
- 2026-10-17: Phase 15 implemented — super admin guard/redirects, public org signup with enforced
  email verification and welcome email, org directory/detail/onboarding/update/suspend/reinstate/
  delete, suspension lock-out page and paused scheduled work, platform analytics + per-org
  drill-down, saved reports with relative ranges and CSV export, console sidebar/palette.
- 2026-10-20: `SuspendOrganizationAction` and `DeleteOrganizationAction` cancel the org's
  scheduled posts the vendor is already holding (`VendorScheduleService::releaseScheduled()`,
  best-effort). See `publishing.md`.
- 2026-10-20: Phase M-1: org delete needs the password; report export behind password confirmation; lists on `DataTable`; Pulse dashboard.
- 2026-10-28: Phase CG-2.6 — plan controls. The org detail page (`super-admin/organizations/show`)
  gained a "Plan & billing" panel: override an org's plan, grant temporary AI credits, and set a
  per-org Stripe billing override (`setPlan`/`grantCredits`/`setBilling` on `SuperAdmin\OrganizationController`
  → `Set`/`Grant` Actions, each writing a `plan_audit_logs` row). A new platform-settings page
  (`SuperAdmin\PlatformSettingController`, route `super-admin.settings.*`, nav chapter "Billing")
  holds the **global Stripe switch** (`PlatformSettings` key `billing.enabled`, default off) and a
  plan-distribution table. `GetOrganizationDetailAction` now returns a `billing` block + `planOptions`.
  Full detail in `plans.md`.
