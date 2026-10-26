# Analytics & Reporting (organization)

## Purpose
Chapter **07 Analytics**: every agency gets the same quality of analytics and saved reporting the
super admin console got in Phase 15, scoped to its own organization — how clients are tracking, what
is going out, where approvals stall, who is carrying the load, what has been billed and what AI is
costing — and, from Phase M-3, how people responded (engagement, followers, best times) and the client-facing monthly report. A read/reporting layer over tables the app already writes (`clients`, `posts`,
`post_targets`, `post_activity_logs`, `invoices`, `ai_usage_logs`, `ai_trends`, `ai_post_ideas`,
`daily_briefs`, `users`, and the M-3 metric tables); no stored aggregates. Covers Phase 17 (Steps
17.1–17.10), Phase 12 (engagement, M-3.1), I-10.2 (best time, M-3.2) and Phase 8 (client reports,
M-3.3).

## Models / Tables
- `OrganizationReport` (`organization_reports`, migration `2026_10_17_130100`): `org_id` (FK,
  `cascadeOnDelete`), `name`, `description`, `filters` (JSON, the
  `OrganizationAnalyticsFilters::toStoredArray()` shape — never an org id), `created_by` (FK → `users`,
  `nullOnDelete`), `last_run_at`, and Step 17.8's `schedule` (`App\Enums\ReportSchedule`:
  `weekly|monthly`, nullable), `recipient_ids` (JSON list of same-org staff ids), `last_sent_at`.
  Visible to the whole org. Factory `OrganizationReportFactory` (`scheduled()` state).
- Migration `2026_10_17_130000_add_analytics_indexes`: `post_activity_logs (post_id, to_status,
  created_at)` and `(to_status, created_at)`, `posts (org_id, status)` and `(org_id, created_at)`,
  `invoices (org_id, status, issue_date)`. (`ai_usage_logs (org_id, created_at)` already existed.)

## Shared analytics core (Step 17.1)
Used by both the platform console (`GetPlatformAnalyticsSummaryAction`) and organization analytics,
so the two can't drift:
- `App\Support\Analytics\AnalyticsRange` — preset/custom range resolution (`7d|30d|90d|12m|ytd|custom`,
  bad input → `30d`) **in a timezone**; `from`/`to` stay local, `fromUtc()`/`toUtc()`/`utcBounds()`
  for timestamp columns, `dateBounds()` (local dates) for `date` columns (`issue_date`),
  `granularity()` (day ≤ ~2 months, else month), `toStoredArray()` (presets relative, custom pinned),
  `toArray()`.
- `App\Support\Analytics\SeriesBuckets` — the bucket list and `countByBucket()` / `sumByBucket()`;
  converts every instant into the range's timezone *before* formatting the key (calendar dates are
  bucketed as-is with `calendarDates: true`).
- `App\Support\Analytics\AnalyticsScope` — *which* rows: org ids, optionally narrowed by client ids
  (posts, invoices, AI usage, clients), campaign ids and platforms (posts only). `posts()` builder +
  `applyToClientOwned()`.
- `App\Services\Analytics\PostActivityMetrics` (transitions into a status, publish outcomes, success
  rate, series times), `RevenueMetrics` (billed/issued/paid/outstanding/run-rate, `totalsByCurrency()`,
  `primaryCurrency()`), `AiUsageMetrics` (calls, summary, `byMethod()`). Org-only:
  `ApprovalMetrics` (turnaround, first-time approval, oldest waiting; `percentile()` =
  `PERCENTILE.INC`) and `TeamWorkloadMetrics`.
- `App\Support\SuperAdmin\AnalyticsFilters` now composes an `AnalyticsRange` (always UTC) and keeps its
  public API (`range`, `from`, `to`, `orgIds`, `metrics`, `includes()`, `forOrganization()`, …).
- `App\Support\Analytics\AnalyticsCsv` — shared CSV flattening (section block, per-currency money
  rows, tables); `PlatformAnalyticsCsv` and `OrganizationAnalyticsCsv` are thin mappers.
- `App\Support\Pdf\PdfBrand` + `resources/views/pdf/partials/brand-fonts.blade.php` — the Alima
  artwork/fonts now shared by the invoice PDF and the analytics report PDF.

## Actions / Services / Contracts
- `App\Support\Analytics\OrganizationAnalyticsFilters` (Step 17.2): `AnalyticsRange` in the org's
  `default_timezone` + `clientIds`, `campaignIds`, `platforms`, `metrics`
  (`METRICS = clients, posts, approvals, team, revenue, ai`). `fromArray($input, $organization)` takes
  the org id and timezone from the model, never input, and re-checks client/campaign ids against the
  org (foreign or deleted ids drop quietly). `forClient()`, `withMetrics()`, `singleClientId()`,
  `scope()`, `toStoredArray()`, `toArray()`, `toQuery()`.
- `App\Support\Analytics\AnalyticsAccess` — `visibleMetrics(User, filters)` (drops `revenue` without
  `viewRevenueAnalytics`), `canViewRevenue()`, `canViewAiCost()`.
- `App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction(Organization, filters, User $viewer)`
  (Step 17.3): returns `scope` (`organization|client`), `organization` (name, timezone, currency),
  `client` (single-client runs), `filters`, `metrics` (visible), sections `clients`, `posts`,
  `approvals`, `team`, `revenue`, `ai` (or `null`), `engagement` (`null`), `primary_currency`,
  `series` (created / published / failed / sent_for_approval / approved, + `invoiced` only with
  revenue), `top_clients` (org runs; `invoiced` only with revenue) and `top_campaigns` (client runs).
  Throws if the filters belong to another org. Logs org/user ids, range and filter ids (no names, no
  money).
- `GetAnalyticsFilterOptionsAction` — the org's clients, campaigns and connected platforms; and
  `recipients()` (staff) for scheduled reports.
- `CreateOrganizationReportAction`, `UpdateOrganizationReportAction` (a changed schedule resets
  `last_sent_at`), `DeleteOrganizationReportAction`, `RunOrganizationReportAction` (rebuilds the
  filters, runs the summary for the **current viewer**, stamps `last_run_at`). Payload normalised by
  `App\Support\Analytics\OrganizationReportAttributes` (recipients = same-org staff only; a schedule
  with no recipients is no schedule).
- `GenerateOrganizationReportPdfAction` (Step 17.7) — DomPDF `pdf.analytics-report`: Ink cover band
  (mark tile, org, range), headline with one teal accent word, numeral spec row, an inline SVG
  activity chart, then one page per section present in the summary; footer "Marketing brighter
  together." + teal wordmark. Nothing is stored.
- `SendScheduledOrganizationReportsAction` + `analytics:send-scheduled-reports` (daily 07:00,
  Step 17.8) — `App\Mail\OrganizationReportMail` per recipient (CSV + PDF attached, headline figures).
- Policies: `OrganizationPolicy::viewAnalytics` (same-org Owner/Strategist/Designer/Viewer),
  `viewRevenueAnalytics` (Owner/Strategist), `viewAiCost` (Owner). `OrganizationReportPolicy`:
  `viewAny`/`view` = analytics viewers of that org; `create` = Owner/Strategist; `update`/`delete` = the
  creator (Owner/Strategist) or an Owner. Super admins pass all (`Gate::before`).

### Role / section matrix
| Section | Owner | Strategist | Designer | Viewer |
|---|---|---|---|---|
| `clients`, `posts`, `approvals`, `team` | ✓ | ✓ | ✓ | ✓ |
| `revenue` | ✓ | ✓ | – | – |
| `ai` (counts) | ✓ | ✓ | ✓ | ✓ |
| `ai.cost_estimate` | ✓ | – | – | – |

`ClientReviewer` gets a 403 on every Phase 17 route. Sections a role can't see are `null` (dropped,
never refused) in props, saved-report runs, CSV, PDF and emails.

## Routes / Controllers
Staff group (`auth`, `verified`, `org.member`):
- `GET /analytics` → `Analytics\AnalyticsController@index` (`analytics.index`) — props `summary`,
  `filters`, `filterOptions`, `can` (`viewRevenue`, `viewAiCost`, `saveReport`, `export`).
- `GET /analytics/export` (CSV) and `GET /analytics/pdf` — the current view.
- `GET /clients/{client}/analytics` → `@client` (`clients.analytics`, Step 17.5) — `ClientPolicy::view`
  + `viewAnalytics`; another org's client → 403.
- `Route::resource('analytics/reports')` → `Analytics\ReportController` (`analytics.reports.*`,
  parameter `{report}`), plus `GET analytics/reports/{report}/export` and `/pdf`.
- `/preview` mirror: `analytics`, `analytics/export`, `analytics/pdf`, `clients/{client}/analytics`,
  `analytics/reports` (index, show, export, pdf).
- Form Requests: `Analytics\OrganizationAnalyticsFilterRequest` (`filterRules(int $orgId, $prefix)`,
  `client_ids.*`/`campaign_ids.*` `exists` scoped to the viewer's org, `filters()` accessor) and
  `Analytics\SaveOrganizationReportRequest` (nests the same rules under `filters.`, plus `schedule` and
  same-org staff `recipient_ids`).
- Shared Inertia prop `auth.can.viewAnalytics` gates the sidebar / ⌘K entry (under `auth` because many
  pages pass their own top-level `can`).
- Super admin: `GET /super-admin/organizations/{organization}/analytics` now runs this action with the
  super admin as viewer (every section, org timezone) — Step 17.9.

## Frontend
- `resources/js/components/analytics/`: `format.ts` (range labels, `describeRange`, `formatHours`,
  `formatPercent`, `pad`, `primaryTotal`, `tickInterval`), `chart-theme.ts`, `series-charts.tsx`
  (`PublishingChart`, `CountBarChart`, `PairedBarChart`, `AmountAreaChart`), `figures.tsx` (`MoneyRow`,
  `FigureList`, `ShareBars`), `filter-controls.tsx` (`RangePicker`, `MultiSelectFilter`),
  `organization-filter-bar.tsx`, `organization-analytics-view.tsx`, `types.ts`. The super admin
  `analytics-filter-bar` and `platform-analytics-view` are rebuilt on these, so both surfaces share one
  visual language.
- Pages: `analytics/index` (ChapterHero with the published count as the Lime hero numeral, "Save as
  report" (spark), Export CSV/PDF, filter bar, view), `analytics/client`, `analytics/reports/{index,
  edit,show}`; `super-admin/organizations/analytics` renders the org view (`links={false}`).
- `lib/chapters.ts`: **07 Analytics** (`LineChart`, electric swatch, `/analytics`); Staff → 08,
  Settings → 09. Overview's publish-attempts and revenue panels link to `/analytics` with that section
  pre-selected; the client page's "More" section box has an "Analytics" entry.

## Phase M-1 additions
- `analytics/export` and `analytics/reports/{report}/export` (CSV) are behind `password.confirm`;
  the PDFs aren't.
- Saved reports list on the shared `DataTable`; the custom range uses the shared `DatePicker`.

## Phase M-3 — Prove results (Steps M-3.1 to M-3.3, `mvp.md`)

### Engagement metrics (M-3.1 / I-10.1 / Phase 12)
- **Tables** (migration `2026_10_23_100000`): `post_target_metrics` (append-only: `org_id`,
  `post_target_id`, `impressions`, `reach`, `views`, `likes`, `comments`, `shares`, `saves`,
  `clicks`, `engagement` = likes+comments+shares+saves over the reported ones, `source`,
  `fetched_at`; model `PostTargetMetric`); `social_account_metrics` (one row per account per day in
  the **account's** timezone: `followers`, `reach`, `impressions`, `profile_views`; unique
  `(social_account_id, metric_date)`; model `SocialAccountMetric`). `post_targets` gains
  `vendor_request_id`, `platform_post_id`, `post_url`, `published_at`, `metrics_synced_at`,
  `metrics_error`, `latest_metrics_id` (→ newest reading); `social_accounts` gains
  `metrics_synced_at`, `metrics_error`. Every metric is nullable — **null means "not reported",
  never zero**.
- **Source:** `App\Contracts\EngagementSource` (`key()`, `postMetrics(PostTarget)`,
  `accountMetrics(SocialAccount)`; null = nothing to report, `EngagementSourceException` = vendor
  failure with a safe message) bound to `App\Services\Analytics\Engagement\UploadPostEngagementSource`
  (`GET /uploadposts/post-analytics/{request_id}?platform=`, else the live
  `?platform_post_id=&platform=&user=`; `GET /analytics/{profile}?platforms=` with `page_id` /
  `page_urn` from the account's settings); `NullEngagementSource` is the no-op. Kept apart from
  `PublishAdapter` on purpose (a metrics-only fallback can be swapped in). DTOs
  `App\Support\Analytics\Engagement\PostMetricsReading` / `AccountMetricsReading` map each
  network's field names (TikTok `favorites`, X `retweets`/`replies`, Pinterest `pin_clicks`…).
- **Registry:** `config/platforms.php` `account_metrics` / `post_metrics` (default true; Google
  Business `post_metrics: false`), on `PlatformSpec` and the shared `platforms` prop.
- **Sync:** `SyncPostTargetMetricsAction` (one reading appended + pointer, in a transaction),
  `SyncSocialAccountMetricsAction` (today's followers/profile views + the daily reach/impressions
  series; never overwrites a captured follower count), `SyncOrganizationEngagementAction` (connected
  accounts, then channels published in the last `analytics.engagement.window_days`, oldest-read
  first, ≤ `max_posts_per_org`, skipping anything read within `min_interval_hours` unless forced;
  suspended / keyless orgs skipped; busts the analytics cache). `SyncOrganizationEngagementJob`
  (reports queue, unique per org) ← `analytics:sync-engagement {--org=} {--force}` every 30 min (so a
  freshly published post shows stats quickly). `min_interval_hours` (default 1) throttles how often a
  given post is re-read, so the frequent sweep doesn't hammer the vendor; set
  `ANALYTICS_ENGAGEMENT_MIN_INTERVAL_HOURS=0` for maximum freshness.
  Failures are stored on the row (`metrics_error`) and logged, never thrown.
- **Read layer:** `App\Services\Analytics\EngagementMetrics` — `channels()` (published channels in
  scope + range, latest reading each), `totals()`, `rate()` (interactions ÷ reach, else impressions,
  else views — summed, not averaged), `followers()` (start vs end of range per account, carried
  forward per day), `byPlatform()` (with `available` + "Post engagement isn't available for
  {platform}."), `topPosts()`, `series()`, `bestTimes()`; `summary()` fills the `engagement`
  section (new metric key `engagement`, visible to every analytics role). Also used by campaign
  KPIs / top posts / wrap snapshots (`campaigns.md`) and the client report.
- **Exports:** the CSV gets the engagement figures (per platform keyed by platform), a series table
  and a top-posts table; the analytics PDF gets page **07 — Engagement**.
- **Frontend:** `components/analytics/engagement-section.tsx` (`EngagementPanels`,
  `TopPostsList`, `BestTimesPanel`, `reported()` / `signed()`), `TotalLineChart` in
  `series-charts.tsx`, the "Interactions" figure in the spec row.

### Best time to post (M-3.2 / I-10.2)
- `App\Services\Analytics\BestTimeService::forAccounts(list<int>)` — per account, measured channels
  published in the last `analytics.best_time.lookback_days`, slotted by ISO weekday + a
  `block_hours` window **on the account's clock**, ranked by average engagement rate (or average
  interactions when too few readings carry an audience figure — `ranked_by`). Slots need
  `min_posts`; an account needs `min_account_posts` or it's `learning` with no slots.
- Shown on the client drill-down (`engagement.best_times`, null on org-wide views) and in the post
  editor's channel rows (`targetAccounts.*.best_time`; a chip picks the next such slot via
  `nextWeeklySlot()` in `lib/timezones.ts`). Top posts ship in the engagement section; pillar,
  format and hashtag breakdowns are not built (MVP note).

### Client monthly reports (M-3.3 / I-10.3 / Phase 8)
- **Table** `client_reports` (migration `2026_10_23_100200`; model `ClientReport`, org-scoped):
  `client_id`, `period_start/end`, `status` (`ClientReportStatus`: draft | published),
  `commentary`, `snapshot` (json), `snapshot_at`, `published_at`, `published_by`, `sent_at`,
  `recipients_count`; unique `(client_id, period_start)`. `clients.report_schedule`
  (`ClientReportSchedule`: `review` default | `automatic` | `off`).
- **Actions:** `BuildClientReportSnapshotAction` (one client, one org-timezone month: posts
  published by platform, engagement + followers, top posts, the client's own approval turnaround,
  posts waiting on them now, campaign wrap reports shared that month — no billing, no thumbnails,
  no internal stages), `PrepareClientReportAction` (create/refresh a draft; a published report is
  returned untouched), `UpdateClientReportAction` (commentary, drafts only),
  `PublishClientReportAction` (final rebuild, **freeze**, then after commit
  `ClientReportPublishedMail` with the PDF to `ClientReportRecipients::for()` — the client's portal
  reviewers plus its approval email, deduplicated), `GenerateClientReportPdfAction`
  (`pdf.client-report`), `UpdateClientReportScheduleAction`, `SendMonthlyClientReportsAction` +
  `analytics:send-client-reports` (hourly; from `client_reports.send_day` at `send_hour` in each
  org's timezone prepares last month's report for active clients not `off` and created before
  the month ended — `automatic` publishes, `review` notifies the client's owner, else the org's
  Owners/Strategists, with `ClientReportReady`; idempotent per client and month).
- **Policy** `ClientReportPolicy`: staff of the org read; Owner/Strategist prepare, edit, publish,
  change the schedule (drafts only); a client reviewer views only published reports of their own
  client. `EnsureClientPortalAccess` 404s a `{report}` from another client or still a draft.
- **Routes** (staff): `GET|POST /clients/{client}/reports` (`clients.reports.index|store`),
  `PUT /clients/{client}/report-schedule`, `GET|PUT /client-reports/{report}`,
  `POST /client-reports/{report}/refresh|publish`, `GET /client-reports/{report}/pdf` →
  `Analytics\ClientReportController`; (portal) `GET /portal/reports`, `/portal/reports/{report}`,
  `/portal/reports/{report}/pdf` → `Portal\PortalReportController`. Preview mirror: index + show.
- **Frontend:** `components/client-reports/client-report-document.tsx` (the report as the client
  sees it), pages `client-reports/{index,show}` and `portal/reports/{index,show}`; "Monthly
  reports" button on the client analytics header (not in the client page's More box, which already links to Analytics); "Reports" in the
  portal nav. Status kind `clientReport`.

## Business Rules
- **Timezone:** ranges and daily buckets follow `organizations.default_timezone` (a post published at
  23:30 UTC lands on the next local day in Dubai or London-in-BST). The platform console stays UTC.
- **Tenant safety:** the org id is never read from input; foreign client/campaign ids are validation
  errors on the page and silently dropped from stored reports.
- **Money is never summed across currencies.** Per-currency lists everywhere; the series and per-client
  invoiced use `primary_currency` = the org's default when it invoiced in it in the range, else the
  most-invoiced currency. Retainers are a monthly equivalent (quarterly ÷ 3, annual ÷ 12,
  project-based excluded) in the org's default currency (retainers carry no currency of their own).
- **Transitions, not timestamps:** published/failed/sent/approved are counted from
  `post_activity_logs` at the moment they happened; note rows that don't change status
  (`from_status = to_status`, e.g. "Added 2 AI images") are never counted (also fixed for the platform
  console).
- **Approvals:** client turnaround = entering `waiting_client` → the very next transition when it's
  `approved`/`changes_requested`, counted when the decision is in range; internal = entering
  `internal_review` → whatever came next; first-time approval = first approvals in range with no prior
  `changes_requested`. Medians/p90s interpolated (`PERCENTILE.INC`).
- **Snapshots vs. ranges:** client health, pipeline, scheduled and waiting-on-client are right now;
  the UI labels them that way.
- **Workload, not a leaderboard:** `team` lists every staff member sorted by name, no ranks or scores.
  Client-side decisions (review link with no user, or a portal `ClientReviewer`) are one separate
  figure; system transitions (no actor) are not attributed.
- **AI:** images from `ai_usage_logs`; text AI (trends, ideas, daily briefs) isn't vendor-logged until
  Step 2.8, so its successes are the rows it persisted; captions only appear once logged. Cost is
  Owner-only.
- **Run-time permissions:** a report saved by an Owner with billing, run by a Viewer, shows billing as
  `null`; scheduled emails run once **per recipient**, so a Designer's email/CSV/PDF has no billing.
- **Schedules:** weekly from Monday 00:00, monthly from the 1st, in the org's timezone; at most once
  per period (`last_sent_at`); suspended orgs skipped; recipients who left the org or became client
  reviewers are skipped; no current recipients → nothing sent, no stamp.
- **Filenames:** `Str::slug(name)-Y-m-d` (org-local date); the current view uses `{org|client}
  analytics`.

## Tests
- Phase M-3: `EngagementSyncTest` (append-only readings + pointer, null source no-op, failures
  recorded, daily account rows in the account's timezone, org sweep window/interval/force/guards,
  command, webhook keeps request id / post id / URL, bad URL dropped), `UploadPostEngagementSourceTest`
  (faked vendor shapes, Google Business never requested, safe errors, page id), `EngagementSummaryTest`
  (latest-reading totals and rate, nulls not zeros, "not available" platforms, follower growth +
  series, ranking, tenant isolation and filters, every role, CSV, campaign KPIs, top posts,
  frozen wrap snapshot), `BestTimeTest` (account clock, rate vs interactions, learning, editor +
  drill-down props), `ClientReportsTest` (client-safe snapshot, permissions, month validation,
  commentary/refresh, freeze + one email per contact, PDF attachment, portal isolation, wrap
  reports, schedule, monthly run per org timezone, idempotency, skips). Shared fixtures
  `Concerns/BuildsEngagementFixtures`, `Concerns/FakeEngagementSource`.
- `tests/Unit/Support/AnalyticsRangeTest.php`, `SeriesBucketsTest.php` — presets in Dubai/London across
  the October DST change, custom ranges, fallbacks; the 23:30 UTC bucketing regression.
- `tests/Unit/Support/PlatformAnalyticsCsvTest.php` — super admin CSV byte-for-byte unchanged
  (frozen copy of the Phase 15 algorithm).
- `tests/Unit/Services/ApprovalMetricsPercentileTest.php`.
- `tests/Feature/Analytics/Concerns/BuildsAnalyticsFixtures.php` — the hand-built two-org fixture.
- `OrganizationAnalyticsSummaryTest` — exact figures per section, turnaround median/p90, timezone
  bucketing at action level, tenant isolation in every section, role gating, filters, empty org,
  cross-org filters rejected.
- `OrganizationAnalyticsAccessTest` — filters (timezone from org, sanitising, storage, `forClient`),
  policy matrix across all roles + super admin + outsider, `visibleMetrics()`.
- `AnalyticsPageTest` — props per role, client reviewers 403 on every route, foreign client id
  validation error, query round-trip, preview mirror, client drill-down ≡ filtered summary, super admin
  drill-down ≡ Owner's view.
- `OrganizationReportTest` — CRUD, relative vs pinned storage, run ≡ direct action, run-time
  permissions, Designer read-only, creator/Owner edits, cross-org 403, deleted client dropped, builder
  prefill, schedule recipients, schedule reset.
- `AnalyticsExportTest` — CSV per section, Designer/Strategist exports, flattener, report CSV/PDF,
  current-view PDF, PDF view only drawing visible sections.
- `ScheduledReportsTest` — once per week/month (org timezone), suspended orgs, departed recipients,
  per-recipient role scoping, attachments and subject.
- `tests/Feature/SuperAdmin/PlatformAnalyticsTest` drill-down test updated for Step 17.9.

## Known Gaps / Open Questions
- Engagement field names are mapped from Upload-Post's **docs** (read 21 Oct 2026); the live sandbox
  check M-0.2 asked for is still due — if a network names a metric differently, extend
  `PostMetricsReading::fromVendor()`. A scheduled upload's `request_id` only arrives with the
  webhook; targets that resolved via the status poll without one fall back to the live endpoint
  (100 requests / 5 min) once their `platform_post_id` is known.
- Follower dates are the account's local days while ranges are the org's local days; a day's
  offset can shift a count across a range edge.
- Link clicks stay "not reported" unless a network sends them (short links are I-10.4).
- Client reports carry no line-item "work summary" (Phase 8's original idea) — invoice items are
  billing data; revisit with M-5 if agencies ask for a non-money work log.
- Monthly report gating (scheduled reports are a time-saver in the pending pricing) waits on M-10.1.
- Step I-2.7 moved pairing (window functions), series bucketing (`CASE` over org-timezone edges),
  days-to-pay and health into SQL; medians/p90 are still taken in PHP over the in-range durations
  (MySQL 8 has no `percentile_cont`). Benchmark on MySQL (one org, 50k posts, 100k transitions,
  90-day range): ~1.3–1.6 s cold, 0.3 ms cached — the 500 ms cold target is **not** met. The cost
  is `post_activity_logs` having no `org_id`: every query reaches the org through
  `post_id IN (org's posts)`, so MySQL walks all of the org's posts (forcing range-first was 9×
  faster in a single-tenant benchmark but would scan every tenant's logs in production). Fix:
  denormalize `org_id` onto `post_activity_logs` with an `(org_id, created_at)` index.
- Text AI generations aren't vendor-logged until Step 2.8, so failures for trends/ideas/briefs and all
  caption usage only appear once it lands.
- Posts already waiting on a client before activity logging existed have no send time, so the oldest
  wait can't be timed (the UI says so).
- `/preview` sidebar links leave the mirror (existing behaviour for every chapter).
- Browser visual check: light mode at desktop width verified (no console errors after the hydration
  fix); dark mode and phone width could not be screenshotted in this session (the automation tab was
  throttled) — check manually.

## Changelog
- 2026-10-23: Phase B-8 (business insights) reuses this core — `GetBusinessInsightsAction` reads
  `EngagementMetrics::summary()` scoped to the primary brand (reach/engagement, top posts, follower
  growth), and `App\Services\Business\BestTimeService` wraps `Analytics\BestTimeService::forAccounts()`
  with a platform-default fallback (`config('analytics.best_time.defaults')`) for the business shell's
  best-times. The creator media kit (`BuildMediaKitAction`) is built only from this layer's
  public-safe output (published posts, public follower counts). Agency analytics is unchanged. See
  `.junie/modules/business-workspaces.md`.
- 2026-10-21: Engagement sync cadence raised from nightly (04:00) to every 30 min so a freshly
  published post surfaces its stats quickly, and `analytics.engagement.min_interval_hours` default
  lowered 20 → 1 (the frequent sweep would otherwise skip everything on the 20h guard). Each post is
  now re-read at most hourly regardless of how often the sweep runs; `ANALYTICS_ENGAGEMENT_MIN_INTERVAL_HOURS=0`
  disables the throttle for maximum freshness. `SchedulerHeartbeat` interval updated to 30 so the
  super-admin dashboard flags the job stale correctly. Note: network/vendor data latency still gates
  how soon a brand-new post has any engagement to report.
- 2026-10-21: Phase M-3 (Steps M-3.1–M-3.3) — engagement metrics (`EngagementSource` + Upload-Post
  implementation, nightly sync, `EngagementMetrics` read layer, `engagement` section in the page,
  CSV, PDF), best time to post per account (`BestTimeService`, editor chips, drill-down panel),
  and client monthly reports (`client_reports`, review/automatic/off schedule, portal + emailed
  PDF, frozen on publish). Fixed: follower counts compared a `date` column to a bare date string
  (missed the last day on SQLite) — now `whereDate`.
- 2026-10-18: Campaigns enhancement C-8.3 — the revenue section gains `by_campaign` (`RevenueMetrics::byCampaign`: billed invoice lines tagged with a campaign, per currency, top 10), shown as "By campaign" in the Billing panel; same Owner/Strategist rule.
- 2026-10-18: Campaigns enhancement C-7.1/C-7.2 — `CampaignKpiService` computes campaign KPIs from `ApprovalMetrics` with a campaign-scoped `AnalyticsScope`; engagement metrics are marked unavailable until engagement tracking exists. Revenue by campaign (C-8.3) is not built yet.
- 2026-10-18: Step I-2.7 — `ApprovalMetrics::walk()` pairs transitions with `LAG`/running `SUM() OVER`
  in one query and ships only in-range rows; `SeriesBuckets::countByBucketSql()` /
  `countByBucketAndGroupSql()` bucket in SQL with edges computed in the org timezone (DST-correct,
  no MySQL tz tables); average days-to-pay is an SQL `AVG`; health reads the stored column
  (Step I-2.5). `App\Support\Analytics\SqlDialect` holds the MySQL/SQLite differences.
  `App\Services\Analytics\AnalyticsCache` caches `GetOrganizationAnalyticsSummaryAction` for 5 min
  per (org, filters, viewer, role), invalidated by a per-org version bumped on post create/status
  change/delete, invoice and client writes, AI usage logs, and stored-health changes. All existing
  analytics tests pass unchanged; new `tests/Feature/Analytics/AnalyticsCacheTest.php`. New index
  `post_activity_logs (created_at, post_id)` for platform-wide ranges. See Known Gaps for the cold
  benchmark.
- 2026-10-18: Step I-1.7 — analytics CSV/PDF and saved-report exports (and their preview mirrors) are throttled by the `exports` limiter (10/min per user). See `ai.md` for the full limiter table.
- 2026-10-18: Phase 17 implemented — shared analytics core extracted (platform output unchanged),
  org filters/policies/access, `GetOrganizationAnalyticsSummaryAction` with composite indexes,
  chapter 06 Analytics page + client drill-down, saved reports, CSV + branded PDF exports, scheduled
  report emails, super admin drill-down on the org action. Fixed: note rows counted as transitions;
  SSR hydration mismatch in `formatCurrencyCompact`.
- 2026-10-20: Phase M-1: CSV exports behind password confirmation; reports list on `DataTable`.
