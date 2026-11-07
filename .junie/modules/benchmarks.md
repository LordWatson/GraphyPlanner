# Competitor Benchmarks

## Purpose

Phase GTM-4 of `.junie/plans/go-to-market.md`. The credible version of "listening": a brand's own
figures beside the public figures of profiles it chooses to track, on its analytics page and in its
monthly client report.

It exists because of the category definition as much as the feature. Metricool sells 100 tracked
profiles at €16/month and Gartner's quadrant is now "Social Media Management **and** Listening", so
having nothing here loses RFPs before the product is even looked at. What this is **not** is
mention-volume crawling or sentiment over the open web (plan §1.1) — it is a nightly read of a
handful of named public profiles.

## The sourcing question, answered (CG open question §5.7 / GTM plan §7)

**Instagram only, through Meta's Business Discovery.** That endpoint
(`business_discovery.username(...)` on an IG user we own) is what Meta provides for exactly this
job: it is asked **through an Instagram Business account the brand itself owns**, returns only
figures Instagram already shows publicly, and needs no agreement from the profile being read.

No other network we publish to offers a comparable read of an account we don't own, and the
alternative — logged-out HTML scraping — is against every one of their terms and would put the
whole product at risk for a nice-to-have. So `CompositeCompetitorProfileSource::supports()` returns
true for Instagram and false for everything else, and `AddTrackedProfileAction` **refuses** another
platform outright rather than accepting a handle that could only ever produce gaps.

This is the answer to "is GTM-4 one platform or four": **one**, until a platform ships an
equivalent endpoint.

## Models / Tables

`2026_12_19_100100_create_tracked_profile_tables.php`, deliberately the same shape as
`social_account_metrics` so our figures and theirs are compared like with like:

- `TrackedProfile` (`tracked_profiles`): `org_id`, `client_id`, `platform`, `handle` (stored
  lowercase, no `@`), `label`, `added_by`, `last_synced_at`, `last_error`. Unique
  `(client_id, platform, handle)`. Carries `BelongsToOrganization`. **No token, no content and no
  link to a `SocialAccount`** — this is a handle we do not own.
- `TrackedProfileMetric` (`tracked_profile_metrics`): `org_id`, `tracked_profile_id`,
  `captured_on`, `followers`, `post_count`, `avg_engagement`, `fetched_at`. Unique
  `(tracked_profile_id, captured_on)` — one row per profile per day, upserted, so a re-run
  overwrites and the series can never double-count. No `timestamps()`: `fetched_at` is the only
  time that matters.

## Enums / Features

- `App\Enums\Feature::CompetitorBenchmarks` — the gate (Pro, Creator Pro and every agency tier).
- `App\Enums\Feature::TrackedProfiles` — the **limit**: free 0, Pro and Creator Pro 3, agency
  Starter 25 / Standard 50 / Scale 100, `agency_legacy` unlimited. A capacity limit rather than a
  switch, because every profile is a nightly read against somebody else's rate limit.

## Actions / Services / Contracts

- `App\Contracts\CompetitorProfileSource` (`profile()`, `key()`) — public figures for a handle we
  don't own, alongside `ProfileFeedSource` which reads one we do. Bound to
  `CompositeCompetitorProfileSource`.
- `App\Services\Analytics\Benchmarks\MetaBusinessDiscoveryCompetitorSource` — the real one. Reuses
  the grid preview's Graph plumbing exactly (`VendorHttp::forReads`, the Facebook Login token,
  `MetaGraphProfileFeedSource::canRead()` to find a readable owned account).
- `App\Services\Analytics\Benchmarks\NullCompetitorProfileSource` — the honest no-op for tests and
  for every platform with no legitimate read.
- `App\Actions\Benchmarks\AddTrackedProfileAction` — both plan checks, the duplicate check, the
  unsupported-platform refusal, then **one read immediately** so a handle we will never get a
  figure for says so now rather than looking fine until tomorrow.
- `App\Actions\Benchmarks\SyncTrackedProfileAction` — one profile, one row per day, upserted.
  Returns `stored` or `gap`.
- `App\Actions\Benchmarks\SyncOrganizationBenchmarksAction` + `SyncOrganizationBenchmarksJob` +
  `benchmarks:sync` (nightly 04:00, in `SchedulerHeartbeat::COMMANDS`) — the sweep.
- `App\Actions\Benchmarks\GetClientBenchmarksAction` — the panel and report payload, plus the
  plain-English takeaway through the existing `InsightsSummarizer` seam.

## Routes / Controllers

Agency shell, inside the `workspace:agency` analytics group:

- `POST /clients/{client}/benchmarks` → `Analytics\BenchmarkController@store`
- `POST /clients/{client}/benchmarks/{profile}/sync` → `@sync` (read one now)
- `DELETE /clients/{client}/benchmarks/{profile}` → `@destroy`

Writes need the client-**update** ability, not just view: who a brand is measured against is an
account decision, and each profile costs a nightly read.

Frontend: `components/analytics/benchmarks-panel.tsx` on `pages/analytics/client.tsx`, the
"How you compare" block in `components/client-reports/client-report-document.tsx` (portal and
studio) and in `resources/views/pdf/client-report.blade.php`.

## Business Rules

- **A gap is a gap, never a zero.** This is the rule the whole module is built around. A source
  that can't read a profile returns `null` → **no row is written** → the panel shows an em dash and
  the reason. Every figure is nullable from the Graph response through the column, the payload and
  the component. A fabricated 0 would make a competitor look worse than they are, which is the one
  thing a benchmark must never do.
- **Never throw to the page.** Same contract as `ProfileFeedSource`: a blocked, rate-limited or
  private target, a lapsed token or a Meta outage all become `null`, and `CompositeCompetitorProfileSource`
  catches anything an implementation throws anyway.
- **The limit is per organization, not per brand.** Fifty brands each tracking one profile is fifty
  nightly reads against one Graph budget.
- **A downgrade stops the reads, not just the panel.** `SyncOrganizationBenchmarksAction` skips an
  org that has lost `Feature::CompetitorBenchmarks`, so an expired plan stops costing API calls.
- **Already read today = skipped**, and a run stops after `benchmarks.max_profiles_per_org`
  (oldest-read first), so one large roster can't starve the rest.
- **The brand's own Instagram must be connected through Facebook Login** to benchmark anything:
  Business Discovery is always asked through an account we own. Without one the panel renders with
  "No Instagram account connected yet" rather than silently showing blanks.
- **The report block is frozen into the snapshot** like every other figure in a client report, and
  is absent (not empty) when there is nothing to show, so the PDF and the portal page skip the
  section entirely.
- **Only three measures**, because they are the three both sides can be compared on: followers,
  posts in the window, and interactions per post. Nothing stores a competitor's content.

## Tests

- `tests/Feature/Analytics/BenchmarksTest.php` — one row a day with a second run overwriting it;
  a refusing source leaving no row at all, and a partial reading storing only the reported fields;
  the per-plan limit counted across the whole org; a plan without the gate neither tracking nor
  seeing; an unsupported platform refused outright; the sweep skipping what it read today and
  skipping an org that lost the plan; the command queueing only orgs with tracked profiles; the
  payload showing gaps as gaps and a reading outside the window as a gap; the follower change
  across the window; the client analytics page carrying the panel with Instagram as the only
  option; the report snapshot carrying the block only when there is something to show (and the PDF
  rendering it); another org's profile invisible and untouchable.

## Known Gaps / Open Questions

- **One platform.** See the sourcing answer above. This is a deliberate limit, not an omission, and
  the UI says so where somebody might otherwise wonder.
- **No follower-growth chart over time**, only the first-and-last change across the window. The
  daily rows are there to draw one from when somebody asks.
- **No alerting.** Nothing tells a creator that a competitor has overtaken them or posted twice as
  much; the figures wait on the page.
- **`avg_engagement` is from at most `benchmarks.engagement_posts` recent posts** (default 12) and
  rounded to whole interactions, so it is a rough comparator, not a published metric. It also
  measures likes + comments only, because that is what Business Discovery reports.
- **No audit of who added or removed a tracked profile** beyond `added_by` and the log line.
- **B-13.2 (export/deletion)** must list `tracked_profiles` and `tracked_profile_metrics` when it
  lands. Deleting a brand or an org cascades both today.

## Gotchas

- **The handle is normalised, the display name isn't.** `@Rival` and `rival` are the same row;
  `label` is whatever the owner typed and is what charts show.
- **`AddTrackedProfileAction` reads immediately but the read is outside the transaction.** A Graph
  call inside a transaction would hold a row lock for the length of an HTTP request.
- **A reading is dated in the _organization's_ timezone**, not the server's, so it lines up with
  `social_account_metrics` (which are dated in the account's).
- **Benchmarks are a page prop on `analytics/client`, not part of
  `GetOrganizationAnalyticsSummaryAction`.** That summary feeds four surfaces (studio analytics,
  the client drill-down, a saved report run and the super-admin drill-down) and benchmarks belong
  on exactly one of them.

## Changelog

- 2026-11-07: Phase GTM-4 (GTM-4.1, GTM-4.2) built: `tracked_profiles` /
  `tracked_profile_metrics`, the `CompetitorProfileSource` contract with Meta Business Discovery
  and a null implementation, the nightly `benchmarks:sync` sweep, `Feature::TrackedProfiles` and
  `Feature::CompetitorBenchmarks`, the "How you compare" panel on the client analytics page, and
  the benchmark block in the client report (PDF and portal). GTM-4.3 (keyword mentions) was
  **dropped** — see `analytics.md`.
