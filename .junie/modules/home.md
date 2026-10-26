# Home / Dashboard / Needs-attention

## Purpose
Two related but distinct pages, both scoped to the authenticated user's organization:
- `/dashboard` — the landing page shown after login. Summary counts (needs-attention,
  scheduled-next-24h, unapproved-drafts, active-clients), a "Needs attention" list, a "Client
  health" list, an invoice revenue chart, an upcoming-posts calendar snippet, a "Publish
  reliability" chart, and an "Unapproved drafts" list.
- `/home` — the plan's Step 0.12 "Home (needs-attention)" page: failed publishes, posts waiting on
  internal (studio) review, posts waiting on client approval, posts missing required media, and disconnected social accounts, each row linking
  to the relevant post (or client, for account rows). **Done (Step 0.12)**.

## Models / Tables
- Reads `Client` (`clients` table), filtered by `org_id` matching the authenticated user.
- Reads `Invoice` (`invoices` table), filtered by `org_id`, for the invoice revenue chart.
- Reads `Post`/`PostTarget` (`posts`/`post_targets` tables), filtered by `org_id` (and `client_id`
  for a Client reviewer), for the upcoming-posts calendar snippet and for every `/home` section.
- Reads `PostActivityLog` (`post_activity_logs` table), scoped to `Post`s in the user's `org_id`,
  for the "Publish reliability" chart (`to_status` transitions to `failed`/`published`).
- Reads `SocialAccount` (`social_accounts` table), filtered by `org_id`, for the disconnected-accounts
  section on `/home`.

## Phase M-6.5 (Phase 9) — overdue approvals
- `PostApprovalSlaService::isOverdue(Post)` / `overdueQuery(Organization)` — a post in
  `waiting_client` / `changes_requested` is overdue once it has sat there longer than the org's
  `approval_sla_hours`, measured from the latest `PostActivityLog` ("entered this status at"); no SLA
  = never overdue. `GetNeedsAttentionItemsAction`'s `waitingApprovals` bucket now carries an `overdue`
  flag per row and an `overdue` total; `home.tsx` shows an "Overdue" badge. The client portal
  dashboard (`GetPortalDashboardItemsAction`) marks the reviewer's own overdue approvals the same way.
  Reminder job: see `notifications.md`.

## Actions / Services / Contracts
- `App\Actions\Invoices\GetMonthlyInvoiceTotalsAction`: given an `org_id` (and an optional month
  count, default 6), returns the last N months of invoiced vs. paid totals
  (`{month, label, invoiced, paid}[]`), one entry per month (including months with no invoices, as
  zeroes). Only `sent`/`paid` invoices count toward `invoiced`; only `paid` invoices count toward
  `paid`. Grouping is done in PHP (not a driver-specific SQL date function like MySQL's `DATE_FORMAT`)
  so it behaves identically on MySQL (production) and SQLite (tests).
- `App\Actions\Posts\GetUpcomingPostOccurrencesAction`: given the authenticated `User` (and optional
  `days`/`limit`, default 7 days / 5 items), returns the next scheduled `PostTarget`s as flat
  occurrences (`{post_id, target_id, client_name, platform, handle, date, time, scheduled_at_utc}[]`),
  sorted soonest-first. Mirrors `CalendarController`'s org-timezone rendering and Client reviewer
  scoping (only their own `client_id`) so the dashboard snippet and the full calendar never disagree.
- `App\Actions\Posts\GetUpcomingPostCountAction`: given the authenticated `User` (and optional
  `days`, default 7), returns the count of distinct `Post`s with at least one `PostTarget` whose
  `scheduled_at_utc` falls within the next `days` days. Uses the same org/Client-reviewer scoping as
  `GetUpcomingPostOccurrencesAction` so the `summary.scheduledPosts` card and the "Upcoming posts"
  list never disagree on which posts qualify.
- Client/brand counting logic lives directly in `DashboardController`; if more summary metrics are
  added, consider extracting a `DashboardSummaryService`.
- `App\Actions\Home\GetNeedsAttentionItemsAction`: given the authenticated `User`, returns the
  `/home` payload — `{failedPublishes, internalReviews, waitingApprovals, missingMedia,
  disconnectedAccounts}`, each `{items, total}` (Step I-2.2): one bounded query per bucket
  (`limit`, default `DEFAULT_LIMIT = 20`, oldest first) plus one grouped status count and a count
  each for missing media and accounts, so the query count is fixed regardless of history.
  `failedPublishes` also includes `PartiallyPublished` (Step I-1.3).
  - `failedPublishes`/`internalReviews`/`waitingApprovals` are `Post`s with `status === Failed` /
    `InternalReview` / `WaitingClient`. An `InternalReview` post can also appear in `missingMedia`.
  - `missingMedia` is the SQL form of `EvaluatePostChecklistAction`'s `media` rule, over the active
    statuses (idea, draft, internal review, waiting client, changes requested, approved): no
    attached asset, and either no targets or at least one target on a platform outside
    `Platform::textOnlyCapableValues()` (the same `isTextOnlyCapable()` source the checklist uses).
  - `disconnectedAccounts` are `SocialAccount`s with `connection_status` in
    `[NotConnected, TokenExpired]`.
  - `recyclingSuggestions` (Step M-5.8): `is_recyclable`, published posts past their
    `recycle_after_days` window (measured from the latest channel's `published_at`) and not yet
    recycled (`recycledInto` empty). Candidates are few (opt-in per post), so the window is applied
    in PHP over a bounded candidate set to stay portable across MySQL and SQLite. Each row carries
    `recycle_after_days`; the "Ready to recycle" lane's button posts to `posts.recycle`. Hidden from
    `Role::ClientReviewer`.
  - Every post row carries a `post_id` (links to the editor); account rows carry a `client_id`
    (links to the client detail page, since a disconnected account isn't tied to one post).
  - Scoped like `CalendarController`: a `Role::ClientReviewer` would only see their own
    `client_id`'s rows, but in practice they can never reach this action — see Business Rules.
- `App\Actions\Dashboard\GetDashboardNeedsAttentionListAction`: wraps
  `GetNeedsAttentionItemsAction` and flattens its five categories into a single ranked list
  (failed publishes, then disconnected accounts, then internal review, then waiting-on-client, then
  missing-media), each row shaped as `{key, type, title, subtitle, client_id, client_name, post_id}`
  (`type` ∈ `failed`/`disconnected`/`internal_review`/`waiting`/`missing_media`; post-row `key`s are
  `post-{type}-{id}` so the same post appearing in two categories doesn't collide) for the
  dashboard's "Needs attention" card — so the dashboard and `/home` never disagree on what counts
  as needing attention. Takes a `$limit` (default 4, so the card stays short — "View all" links to `/home`)
  and returns `{rows, total}` (Step I-2.2): each bucket only fetches `$limit` rows and `total` is
  the sum of the buckets' SQL counts, which feeds `summary.needsAttention` (no more 100-row list).
- `App\Actions\Dashboard\GetClientHealthSummaryAction`: given an `org_id`, returns clients'
  `Client::health()` plus `post_count` (via `withCount('posts')`) and retainer
  (`retainer_amount`/`billing_cycle_label`), ranked Red first, then Amber, then Green, for the
  dashboard's "Client health" card. Step I-2.5: health is the stored column, so the ranking and
  the limit run in SQL.
- `App\Actions\Dashboard\GetUnapprovedDraftsAction`: given the authenticated `User`, returns
  `Post`s with `status` in `[Draft, InternalReview, ChangesRequested]`, newest-updated first, for
  the dashboard's "Unapproved drafts" card. Scoped like `GetUpcomingPostOccurrencesAction` (a
  `Role::ClientReviewer` only sees their own client's posts).
- `App\Actions\Posts\GetPublishReliabilityStatsAction`: given an `org_id` (and an optional month
  count, default 6), reads every `PostActivityLog` transitioning to `Failed` or `Published` for
  posts in that org, and returns `{monthly: {month, label, failed, published}[],
  averageAttemptsToSuccess: float|null}`. `monthly` mirrors `GetMonthlyInvoiceTotalsAction`'s
  shape/grouping (PHP-side month grouping for MySQL/SQLite parity), one entry per month
  including zero-activity months. `averageAttemptsToSuccess` walks each post's activity log in
  chronological order counting `Failed` transitions until (and including) its first `Published`
  transition in the period — posts that never reached `Published` in the period don't contribute
  an attempt count — then averages that per-post attempt count across all qualifying posts
  (`null` when none published in the period).

## Phase M-2 additions

- The accounts lane ("Accounts to reconnect") also lists connections expiring within 7 days, with a
  `reason` ("Connection expires in 5 days") shown instead of the status pill (M-2.7).

## Routes / Controllers
- `GET /dashboard` → `App\Http\Controllers\DashboardController` (invokable) — renders the
  `dashboard` Inertia page with `summary`, `invoiceTotals`, `upcomingPosts`, `needsAttentionItems`,
  `clientHealth`, and `unapprovedDraftsList` props.
  - `summary`: `{ clients, brands, scheduledPosts, scheduledNext24h, needsAttention,
    unapprovedDrafts, activeClients }`.
    - `clients`/`activeClients` are counts of `Client` rows where `org_id` matches the user's org
      (the latter additionally filtered to `status === Active`), gated by the `viewAny` ability on
      `Client` (both return `0` for roles without list access, e.g. Client reviewer).
    - `scheduledPosts`/`scheduledNext24h` come from `GetUpcomingPostCountAction` (with `days = 7`
      and `days = 1` respectively), gated by `PostPolicy::viewCalendar` (same gate as
      `upcomingPosts`) — return `0` for roles without calendar access.
    - `needsAttention` is `GetDashboardNeedsAttentionListAction($user)['total']`, gated by
      `PostPolicy::viewHome` (same gate as `/home`) — returns `0` for roles without home access.
    - `unapprovedDrafts` is an inline count of `Post`s with `status` in
      `[Draft, InternalReview, ChangesRequested]`, scoped like `upcomingPosts` (Client reviewer
      restricted to their own `client_id`), gated by `PostPolicy::viewCalendar`.
    - `brands` is still hardcoded to `0` until BrandBrain (Step 0.5) aggregation is wired up.
  - `invoiceTotals`: array from `GetMonthlyInvoiceTotalsAction`, or `[]` if the user's role can't see
    billing data (mirrors `ClientPolicy::viewBilling`: only Owner/Strategist).
  - `upcomingPosts`: array from `GetUpcomingPostOccurrencesAction`, or `[]` if the user's role fails
    the `PostPolicy::viewCalendar` gate.
  - `needsAttentionItems`: array from `GetDashboardNeedsAttentionListAction`, or `[]` if the user's
    role fails the `PostPolicy::viewHome` gate.
  - `clientHealth`: array from `GetClientHealthSummaryAction`, or `[]` if the user's role fails the
    `viewAny` ability on `Client`.
  - `unapprovedDraftsList`: array from `GetUnapprovedDraftsAction`, or `[]` if the user's role fails
    the `PostPolicy::viewCalendar` gate.
  - `publishReliability`: `{monthly, averageAttemptsToSuccess}` from
    `GetPublishReliabilityStatsAction`, or `{monthly: [], averageAttemptsToSuccess: null}` if the
    user's role fails the `PostPolicy::viewCalendar` gate (same gate as `upcomingPosts`).
- `GET /home` → `App\Http\Controllers\HomeController@index` (route name `needs-attention`, since
  `home` was already taken by the marketing `welcome` page at `/`) — gated by the new
  `PostPolicy::viewHome` ability, renders the `home` Inertia page with a single `items` prop from
  `GetNeedsAttentionItemsAction`.

## Business Rules
- Counts, invoice totals, and upcoming posts are always scoped to `auth()->user()->org_id`; never
  show cross-organization data.
- If the user lacks permission to view clients, the clients count must be `0`, not an error.
- Invoice totals are only shown to Owner/Strategist roles (same visibility rule as client billing
  fields); other roles receive an empty `invoiceTotals` array and the chart section is hidden.
- Upcoming posts (and the `summary.scheduledPosts` count) are only shown to roles allowed on the full
  calendar (`PostPolicy::viewCalendar`); a Client reviewer only ever sees their own client's posts,
  same as `CalendarController`.
- `/home` is an internal ops view (failed publishes, missing media, disconnected accounts have no
  client-facing meaning), so `PostPolicy::viewHome` is Owner/Strategist/Designer/Viewer only —
  unlike the calendar, `Role::ClientReviewer` is denied outright (403), not silently scoped to `[]`.

## Frontend
> **UX overhaul MOVE 1 (2026-10-24, `.junie/plans/ux-overhaul.md`):** `/dashboard`, `/home` and
> `/my-work` are no longer three separate sidebar chapters. They are the three **views** of a single
> `01 Home` chapter — **Studio** (`/dashboard`), **Needs attention** (`/home`) and **Mine**
> (`/my-work`) — switched by `HomeViewTabs` (`components/home-view-tabs.tsx`), a segmented pill nav
> rendered under the header on each page. The sidebar shows one "Home" item (→ `/dashboard`); the
> other two views are reached from the tabs and from ⌘K ("Needs attention" / "My work" sections
> under the Home chapter). Chapter numbering (`lib/chapters.ts`) shifted: Home 01, Calendar 02,
> **Content 03** (promoted — see MOVE 2/3), Clients 04, Invoices 05, Analytics 06, Staff 07,
> Settings 08.

- `resources/js/pages/dashboard.tsx` is the **Studio** view of chapter **01 — Home**. It opens with `ChapterHero`
  (`components/page.tsx`): an Ink chapter-opener block with the running head (chapter mark + today's
  date), a giant greeting with the first name as the Lime accent word, the one orange dash, a
  `spark` "Review the queue" CTA beside an `on-ink` "Calendar" button, and — as the hero figure —
  `summary.needsAttention` as a giant zero-padded Lime numeral linking to `/home`. The lilac peek
  bleeds off the block's bottom/right edges (the page's one graphic).
- Under the hero, a `StatStrip` (the book's numeral spec row) shows Next 24 hours (links to the
  calendar), Unapproved, Active clients (links to the roster) and Scheduled.
- Below that the data stays flat in `Panel`s on an asymmetric `lg:grid-cols-[1.45fr_1fr]` grid:
  "Needs attention" (rows icon-tagged by `type`, linking to `posts.edit`/`clients.show`, "View all"
  → `/home`) beside "Client health" (`StatusPill kind="health"`); the "Invoiced vs. paid" Recharts
  area chart (hidden without billing access, `chart-1`/`chart-2`) beside "Going out" (next 7 days);
  then "Publish attempts" (published/failed, failure series dashed) beside "Unapproved drafts" —
  each hidden when it has nothing to show.
- `resources/js/pages/home.tsx` is the **Needs attention** view of chapter **01 — Home**. Like the
  Studio view it opens with a `ChapterHero` (Ink opener, orange dash, lilac peek): a voice-led
  headline ("Clear the *queue*." / "Nothing is *blocked*." when nothing is open), a `spark` "Open
  calendar" CTA beside an `on-ink` "See everything" (→ Content hub), and — as the `HeroStat` figure
  — the live open-items `total` as a giant zero-padded Lime numeral ("Things to fix"). Under the
  hero sit the `HomeViewTabs`, then a `StatStrip` with one zero-padded numeral per lane (each cell
  an in-page anchor to its lane; empty lanes render their numeral muted), then the lanes as ruled
  rows — label column (icon, mono index, title + mono count, description) on the left, a divided
  card of rows on the right. A lane with nothing in it collapses to a single "All clear." line
  rather than a card, so the lanes that need action stand out. Post rows link to `posts.edit`;
  account rows link to `clients.show`.
- `resources/js/pages/my-work/index.tsx` is the **Mine** view of chapter **01 — Home**. It also
  opens with a `ChapterHero`: headline "Your *work*." (or "A clear *desk*." when the plate is
  empty), the same `spark` "Open calendar" + `on-ink` "See everything" actions, and a `HeroStat`
  of the `total` on the user's plate (due + review + assigned) as the Lime numeral ("Open items").
  Below the `HomeViewTabs` are the four lenses as `Panel`s — Due soon, Waiting on your review,
  Assigned to you, You were mentioned — each row linking to `posts.edit`.

## Tests
- `tests/Feature/DashboardTest.php`:
  - guests are redirected to login.
  - authenticated users can load the dashboard.
  - `summary.clients` reflects only clients belonging to the user's organization (clients from other
    orgs are excluded).
  - `invoiceTotals` reflects monthly invoiced/paid sums scoped to the user's org (Owner role).
  - `invoiceTotals` is empty for roles without billing access (e.g. Designer).
  - `upcomingPosts` only includes `PostTarget`s scheduled within the next 7 days.
  - `upcomingPosts` is scoped to a Client reviewer's own client, mirroring the calendar page.
  - `summary.scheduledPosts` reflects the same 7-day/org/Client-reviewer scoping as `upcomingPosts`,
    for both the default and Client reviewer scenarios.
- `tests/Feature/HomeControllerTest.php`:
  - guests are redirected to login; authenticated users can load `/home`.
  - a `Role::ClientReviewer` gets a 403 (not an empty page).
  - each of the four sections is populated by a seeded fixture (failed post, waiting-on-client post,
    a media-less active post, a `token_expired` social account) and rows from other organizations
    never leak in.
  - `test_home_lists_posts_waiting_on_internal_review`: only the org's `InternalReview` posts appear
    in `internalReviews` (a `Draft` post and another org's `InternalReview` post are excluded).
- `tests/Feature/DashboardTest.php` (additional cases):
  `test_dashboard_needs_attention_includes_posts_waiting_on_internal_review` — an `InternalReview`
  post is ranked right after a failed publish with `type = internal_review`; `needsAttentionItems`/`summary.needsAttention`
  reflect a failed post + a token-expired account; `clientHealth` is ranked Red-first (an offboarding
  client before a fully-healthy active one); `unapprovedDraftsList`/`summary.unapprovedDrafts`
  include a `Draft` post but exclude a `Published` one; `publishReliability.monthly`/
  `averageAttemptsToSuccess` reflect a post with one `Failed` then one `Published` activity log
  entry (average of `2`), scoped to the user's org (a published post + log from another org is
  excluded).

## Known Gaps / Open Questions
- Previously the dashboard route used `Route::inertia(...)` with no controller, so it always
  rendered a hardcoded `0` regardless of actual data — this was the root cause of "0 clients shown"
  despite clients existing in the database. Fixed by introducing `DashboardController`.
- The `brands` summary count on `/dashboard` remains to be implemented once BrandBrain aggregation
  is wired up.
- `summary.scheduledPosts` was previously hardcoded to `0`, so it always showed "0" on the dashboard
  even with real scheduled posts in the database. Fixed by adding `GetUpcomingPostCountAction`.
- The exact §12 acceptance-test wording for Step 0.12 wasn't available in this repo, so
  `missingMedia`'s "active post" status set and the disconnected-account statuses
  (`NotConnected`/`TokenExpired`) are interim definitions; revisit once Phase 1 (Step 1.6) starts
  actually producing failed publishes and token expiries from real vendor activity.

## Changelog
- 2026-10-24: UI only — the **Needs attention** (`/home`) and **Mine** (`/my-work`) views now open
  with the same Ink `ChapterHero` + `HeroStat` as the Studio view (replacing their `PageHeader`s), so
  all three Home views share one chapter-opener and each leads with its most telling number (open
  items / plate total). Headlines went voice-led (count moved into the `HeroStat`); both heroes carry
  a `spark` "Open calendar" + `on-ink` "See everything" action pair, and My work's now-duplicate
  footer "content hub" link was removed. Also enlarged the `HomeViewTabs` switcher (icon-in-circle +
  inline purpose line per view). No backend/route/data changes.
- 2026-10-24: UX overhaul MOVE 1 (`.junie/plans/ux-overhaul.md`) — collapsed the three front doors
  (Overview, Needs attention, My work) into one adaptive `01 Home` chapter with a `HomeViewTabs`
  segmented switcher (Studio / Needs attention / Mine) on `/dashboard`, `/home` and `/my-work`; the
  attention count badges the switcher. Sidebar + command palette now show one "Home" item;
  `lib/chapters.ts` renumbered and the production engine promoted to a new `03 Content` chapter
  (MOVE 2/3). No backend/route/data changes — `/dashboard`, `/home`, `/my-work` all still resolve.
- 2026-10-23: Step M-5.8 — staff-only `recyclingSuggestions` bucket: evergreen (`is_recyclable`) posts past their `recycle_after_days` window and not yet recycled, each with a one-tap "Recycle" (`posts.recycle`). Home shows a "Ready to recycle" lane. See `posts.md`.
- 2026-10-20: Phase 18 (grid planner) — staff-only `gridRisks` bucket: grids whose order is at risk (a grid post failed or left the schedule while later tiles are still scheduled), flagged by `GridOrderRiskService` and re-checked on read; Home shows a "Grid order at risk" lane linking to the planner's "Hold the rest".
- 2026-10-20: Phase M-2 — expiring connections on Home.
- 2026-10-18: Campaigns enhancement C-1.4 — `GetNeedsAttentionItemsAction` gains a staff-only `campaigns` bucket (overran end date, launching within the window with unapproved/unstarted work, scheduled post on an expired account), found in SQL with a fixed query count; home shows it as the Campaigns lane, and the dashboard has a deferred Live campaigns panel.
- 2026-10-18: Step I-2.2 — needs-attention buckets are bounded SQL queries returning `{items,
  total}` (`Platform::textOnlyCapableValues()` keeps "missing media" in step with the checklist);
  the dashboard card's count is the sum of SQL counts. `home.tsx` shows full lane totals and
  "Showing the oldest N of M" when capped. Tests: fixed query count, bucket cap/total, text-only
  rule (`tests/Feature/HomeControllerTest.php`). MySQL benchmark (50k posts, 50 clients): 139 ms /
  14 queries with a realistic status mix; 457 ms / 20 queries in a stress mix where 20k posts are
  active drafts with no media (the missing-media `NOT EXISTS` dominates). "View all" links wait on
  the Content hub (Step I-4.1).
- 2026-10-18: Phase 17 — the Overview's "Publish attempts" and revenue panels end with a "See full analytics" link to `/analytics` with `posts` / `revenue` pre-selected (shown when `auth.can.viewAnalytics`).
- 2026-10-16: Brand-book overhaul (UI only, no data changes): the dashboard opens with an Ink
  `ChapterHero` (needs-attention count as the hero numeral) and a `StatStrip` instead of four stat
  cards; `/home` became a lane-count `StatStrip` plus ruled lanes, with empty lanes collapsing to a
  one-line "All clear." Superseded the 2026-09-09 gradient-brand hero notes.
- 2026-10-16: Capped the dashboard "Needs attention" card at 4 rows (was 8);
  `summary.needsAttention` still counts every item.
- 2026-10-16: Added an `internalReviews` category (posts in `InternalReview`) to
  `GetNeedsAttentionItemsAction`, ranked after disconnected accounts in the dashboard list
  (`type = internal_review`) and shown as a new "Internal review" lane on `/home`; it counts toward
  `summary.needsAttention`. Dashboard post-row keys now include the type to avoid duplicate keys.
- 2026-10-16: Client reviewers hitting `/dashboard` or `/home` are redirected to `portal.dashboard`
  by `RedirectClientReviewersToPortal` (previously: internal dashboard with 403ing links / a 403).
  `PostPolicy::viewHome` still excludes them as defence in depth.
- 2026-09-08: Introduced `DashboardController` to replace the static `Route::inertia('dashboard', 'dashboard')`
  registration; dashboard now shows a real, org-scoped client count instead of a hardcoded `0`.
- 2026-09-08: Added an org-scoped invoice revenue chart (`GetMonthlyInvoiceTotalsAction` +
  `recharts`-based bar chart), visible only to Owner/Strategist roles.
- 2026-09-09: Added an "Upcoming posts" calendar snippet (`GetUpcomingPostOccurrencesAction`), placed
  next to the invoice revenue chart in a half-width, two-column layout, with a link to the full
  calendar page.
- 2026-09-09: Restyled the dashboard with a gradient-brand hero header and bolder summary cards to
  match the agency-premium brand guidelines, moving it away from a plain whitelabel-SaaS look.
- 2026-09-09: Fixed `summary.scheduledPosts` always showing `0` by adding `GetUpcomingPostCountAction`
  and wiring it into `DashboardController`, scoped/gated the same way as `upcomingPosts`.
- 2026-09-09: Implemented Step 0.12 ("Home / needs-attention") as a new `GET /home` page, separate
  from `/dashboard`: `HomeController` + `GetNeedsAttentionItemsAction` + `PostPolicy::viewHome` +
  `resources/js/pages/home.tsx`, with `tests/Feature/HomeControllerTest.php` covering all four
  trigger types plus org scoping and the Client-reviewer 403.
- 2026-09-16: Filled out the dashboard to match the reference UI: added "Needs attention" (reusing
  `GetNeedsAttentionItemsAction` via the new `GetDashboardNeedsAttentionListAction`) and "Client
  health" (new `GetClientHealthSummaryAction`, reusing `Client::health()`) cards next to each other,
  an "Unapproved drafts" card (new `GetUnapprovedDraftsAction`), and replaced the 3-card summary row
  with a 4-card row (Needs attention, Scheduled next 24h, Unapproved drafts, Active clients).
- 2026-09-20: Added a "Publish reliability" chart (new `GetPublishReliabilityStatsAction`, reading
  `PostActivityLog` transitions) showing failed vs. published counts per month plus the average
  number of attempts it takes to get a post published successfully; restyled the invoice revenue
  chart with gradient bar fills and a headline "paid of invoiced" stat so it reads as less flat/bland.
