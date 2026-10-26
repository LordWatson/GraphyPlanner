# Calendar

## Purpose
Org-wide, read-oriented calendar of every `Post`'s targets (Step 0.11): month / week / day / list
views, a toggle between "each account local" and "my timezone" (org default) display modes, and
filters by client/campaign/status/platform. Builds directly on the Post/PostTarget module (see
`posts.md`) — it does not introduce any new domain model, only a projection over existing `Post`/
`PostTarget` data.

## Models / Tables
- No new tables. Reads `Post`/`PostTarget` (see `posts.md`), `Client`/`Campaign` (for filter option
  lists and display names), and `Organization::default_timezone` (for the "my timezone" mode).

## Actions / Services / Contracts
- `App\Actions\Posts\GetCalendarPostsAction` (Step I-2.3): posts with a target dated in the window,
  eager-loading only those targets and only the columns occurrences need; `reschedulableIds()`
  (the `PostPolicy::reschedule` rule as one query, via `Post::scopeReschedulable` +
  `PostPolicy::NON_RESCHEDULABLE_STATUSES` / `canRescheduleAny()`); `unscheduled()` (paginated,
  20/page) and `unscheduledCount()` for posts with no dated target.
- `App\Support\Calendar\CalendarWindow` (Step I-2.3): the requested `from`/`to` (default: this
  month's Sunday-first 6-week grid in the org timezone; spans capped at 62 days).
  `targetDateRange()` widens the window by two days each side on `post_targets.scheduled_local_date`
  (indexed), since the org-timezone rendering can land up to two days from the account-local date.
- `App\Http\Requests\CalendarRequest`: validates `from`/`to`/`unscheduled_page`, builds the window and
  filters; shared by the portal calendar.
- Drag-to-reschedule writes go through `App\Actions\Posts\ReschedulePostAction` via
  `posts.reschedule` (owned by the posts module — see `posts.md`).
- Step M-5.7 / I-5.1: a single-channel move (week/day time grid) goes through
  `App\Actions\Posts\RescheduleTargetAction` via `posts.targets.reschedule`
  (`POST post-targets/{target}/reschedule`) — only that target's `scheduled_local_date`/
  `scheduled_local_time` change, re-deriving `scheduled_at_utc` from its account timezone, with the
  same vendor-recall / scheduled-re-dispatch lifecycle as the whole-post move.
- Step M-5.7: `CalendarController::newPostOptions` (`GET calendar/new-post-options`, name
  `calendar.new-post-options`) returns one client's target accounts + open campaigns, fetched
  lazily by the empty-slot "New post" dialog. Authorized exactly like creating a post for that
  client (`PostPolicy::create`), org-scoped.

## Routes / Controllers
- `GET /calendar` → `calendar` (`CalendarController::index`) — authorizes via
  `PostPolicy::viewCalendar`, queries the posts in the requested window (Step I-2.3; a `Role::ClientReviewer` is
  scoped to their own `client_id`, mirroring `PostPolicy::viewAny`), applies `client_id`/
  `campaign_id`/`status`/`platform` query-string filters, and renders `calendar/index` with:
  - `occurrences`: one row per `PostTarget` (a post with N targets produces N occurrences), each
    with `account_local` (`{date, time, timezone}` computed from the target's own
    `scheduled_local_date`/`scheduled_local_time`/`SocialAccount::timezone`, same as the Step 0.10
    editor) **and** `org_timezone` (`{date, time, timezone}` computed by converting the target's
    `scheduled_at_utc` into `Organization::default_timezone`). Both are pre-computed server-side so
    the frontend toggle never has to perform its own timezone math or risk collapsing two targets'
    independent local times together.
  - `orgTimezone`, the current `filters`, and `filterOptions` (`clients`, `campaigns`, the full
    `PostStatus`/`Platform` case lists) for the filter selects.
  - `reschedulablePostIds`: ids of the posts in view that pass `PostPolicy::reschedule` for the
    viewer (always `[]` in preview mode), computed as one SQL query. The portal calendar doesn't
    send it, so it never drags.
  - `window` (`{from, to}`), `unscheduledCount`, and the optional `unscheduled` paginator (only sent
    on a partial reload that asks for it — the "Unscheduled" drawer).
- `GET /portal/calendar` → `PortalCalendarController::index` takes the same `from`/`to` window
  (`GetPortalCalendarPostsAction` now requires a `CalendarWindow`).

## Frontend
- `resources/js/pages/calendar/index.tsx` now opens with a gradient-brand hero banner ("Content
  calendar" headline with `text-gradient-brand` treatment + subtitle), matching the same hero pattern
  introduced on the dashboard, clients index, client show, and post editor pages, per the
  agency-premium brand guidelines. The dense calendar grid itself stays flat per the
  data-dense-screen rule; only the header banner carries the gradient/glow.

## Business Rules
- **Drag-to-reschedule** (Step M-5.7 / I-5.1): drag runs on `@dnd-kit` (`DndContext` with a
  `PointerSensor`, 8px activation so a click still follows the chip's link, and a `TouchSensor`
  with a short press delay for phones). `ContentCalendar` takes `canReschedule(occurrence)` plus
  two move callbacks, and nothing is draggable unless the matching one is passed:
  - **Month view** → `onReschedule(occurrence, {from, to, days})`: dropping a chip on another day
    moves the **whole post** — every target shifts by the same number of days and keeps its own
    local time — so all of that post's chips dim while dragging.
  - **Week / day views** → `onRescheduleTarget(occurrence, {date, time})`: these are real time
    grids (an hour row per hour, plus an "All day" rail for untimed targets), so dropping a chip on
    a slot moves **that one channel** to that day + hour, leaving its siblings untouched.
  Every movable chip also carries a keyboard-accessible **Reschedule** popover (a clock button →
  `ReschedulePopover` with a date field, plus a time field in week/day), so rescheduling never needs
  a mouse. A chip can't land on its own slot or a past day (the server re-checks the exact instant).
  The move **never** happens by itself: `calendar/index.tsx` opens `confirmDialog()` naming the
  from/to and channel count, and only on confirm posts to `posts.reschedule` (whole post) or
  `posts.targets.reschedule` (one channel); a validation error (e.g. "can't be moved into the past")
  surfaces as a `toast.error`. The shift is computed from the chip's *displayed* date, so it's the
  same whichever timezone mode is active.
- **Click an empty slot to create** (Step M-5.7 / I-5.1): a hover/focus "+" button on each month
  day cell and each time-grid slot calls `onCreateAt({date, time})`; `calendar/index.tsx` opens
  `CalendarNewPostDialog` seeded with that date (and `HH:00` from the time grid). The dialog picks a
  client, lazily loads that client's accounts + open campaigns (`calendar.new-post-options`), and
  creates the post through `clients.posts.store`, opening it in the editor pre-scheduled.
- `PostPolicy::viewCalendar(User $user)`: same role set as `viewAny`/`ClientPolicy::viewAny`
  (Owner/Strategist/Designer/Viewer), plus `Role::ClientReviewer` (allowed to open the calendar, but
  the controller query itself restricts their results to `client_id = $user->client_id`).
- **Never collapse independent local times** (same invariant as `posts.md`): `account_local` always
  reflects the target's own `scheduled_local_date`/`scheduled_local_time` untouched; only
  `org_timezone` re-derives a display from `scheduled_at_utc`, and it does so per-target
  independently, so two targets on different-timezone accounts render at different `org_timezone`
  wall-clock times even though they're both "the same post."
- Frontend (`resources/js/pages/calendar/index.tsx`, route `calendar`, reachable from the sidebar):
  a view switcher (Month/Week/Day/List, `ToggleGroup`) and a timezone-mode switcher (`ToggleGroup`,
  "Each account local" vs "My timezone (`{orgTimezone}`)") are purely client-side state over the
  single `occurrences` payload — switching either never triggers a new request. The four
  client/campaign/status/platform `Select` filters, however, **do** trigger an Inertia
  `router.get(calendar().url, ...)` (`preserveState`/`replace`) so filtering is server-side and
  respects `PostPolicy::viewCalendar`/client-reviewer scoping. Each occurrence chip links to the
  Step 0.10 editor (`posts.edit`) for org-internal roles; for `Role::ClientReviewer` it instead
  links to `portal.posts.show` (see `client-portal.md`), since that role can't `update` a post and
  the portal page is where the approve/request-changes actions actually live.

## Tests
- `tests/Feature/ReschedulePostTest.php` (see `posts.md`) covers the move endpoint and the
  `reschedulablePostIds` prop.
- `tests/Feature/CalendarControllerTest.php`:
  - `test_targets_render_at_correct_local_times_and_the_timezone_toggle_reflects_org_default` — the
    §12 acceptance test: London/Dubai targets on the same post keep independent `account_local`
    times (both `09:00`, each in its own timezone) and both correctly re-render in the org's
    `America/New_York` default timezone (`04:00`/`01:00` respectively) via `org_timezone`.
  - `test_calendar_supports_filtering_by_client_campaign_status_and_platform` — a `client_id` filter
    excludes the other client's post.
  - `test_client_reviewer_only_sees_their_own_clients_posts_on_the_calendar` — a `Role::ClientReviewer`
    only ever sees their own client's occurrences, even with no filters applied.

## Known Gaps / Open Questions
- The month/week/day/list rendering (grid building, date-key grouping) is done client-side with
  plain `Date`/`Intl` (no date library is installed in this repo) rather than a calendar component
  library. Multi-week scroll is still not attempted.
- Resolved 2026-10-23 (Step M-5.7 / I-5.1): drag-and-drop moved to `@dnd-kit` with pointer + touch
  support and a keyboard-accessible Reschedule popover on every movable chip; the week and day views
  are real time grids; a single channel can be moved on its own (week/day) as well as the whole post
  (month). `@dnd-kit`'s own keyboard-drag sensor is deliberately not wired onto the chip link (it
  stays a real `<a>` for open-in-new-tab); the Reschedule popover is the keyboard path instead.
- Resolved 2026-10-18 (Step I-1.2): `scheduled` posts can be dragged — the move re-dispatches the
  publish job with a fresh dispatch id and the old queued job no-ops.
- Filtering is server round-trip based (query-string driven); there's no client-side-only quick
  filter for instantaneous UI feedback.
- No calendar export (iCal) or "jump to date" picker yet (I-5.3).

## Changelog
- 2026-10-23: Step M-5.7 / I-5.1 — calendar interaction upgrade. Native HTML5 drag-and-drop replaced
  with `@dnd-kit` (pointer + touch); week/day became real time grids (hour rows + an "All day"
  rail); single-channel moves via `RescheduleTargetAction` + `posts.targets.reschedule`; a
  keyboard-accessible `ReschedulePopover` on every movable chip; empty-slot "New post" via a hover
  "+" → `CalendarNewPostDialog` (lazy `calendar.new-post-options`). `content-calendar.tsx` split into
  `CalendarChip` / `MonthDayCell` / `TimeSlotCell` / `TimeGrid` pieces. Tests:
  `tests/Feature/RescheduleTargetTest.php` (single-target move keeps siblings, time-grid time change,
  past/validation/authz/preview), `resources/js/components/calendar/reschedule-popover.test.tsx`
  (keyboard-only reschedule, month date-only vs week/day date+time).
- 2026-10-20: Phase 18 (grid planner) — occurrences carry `grid_plan_id` / `grid_tile_position` (a small grid chip on the internal calendar), and a "Show grid" filter (`grid_id`, `filterOptions.grids`) narrows the calendar to one grid's posts. Tests: `GridKeepTrueTest::test_the_calendar_marks_grid_posts_and_filters_to_one_grid`.
- 2026-10-18: Campaigns enhancement C-3.5 — `campaignLayer` prop (`GetCalendarCampaignLayerAction`: bands for non-archived campaigns overlapping the window, ghost deliverables not yet started, max 40/300, org-scoped) with a toggle in month/week views; posts carry a campaign chip.
- 2026-10-18: Step I-2.3 — the calendar loads only the visible window. `content-calendar.tsx` starts
  from the server's `window`, and moving into another month does a partial reload
  (`only: occurrences, window, reschedulablePostIds`) that keeps the filters; filter changes carry
  the window too. The list view now pages by month. New "Unscheduled" drawer
  (`components/unscheduled-posts-sheet.tsx`, optional prop loaded on open). Tests:
  `tests/Feature/CalendarWindowTest.php` (default grid, span cap, payload identical with 5,000
  posts outside the window, navigation keeps filters, set-based reschedulable ids, unscheduled
  drawer); the existing calendar tests now pin "now" to October 2026 since they use fixed dates.
  MySQL: the window query is a `range` scan on `post_targets_scheduled_local_date_index`.
- 2026-10-18: Step I-1.2 — `scheduled` posts are now draggable (`PostPolicy::reschedule`), and
  `published`/`partially_published` posts are excluded from `reschedulablePostIds`.
- 2026-10-17: Drag-and-drop rescheduling on the month/week views with a confirmation dialog before
  anything moves; new `reschedulablePostIds` prop on `CalendarController::index`.
- 2026-10-16: Brand-book polish of `components/content-calendar.tsx` (UI only): the toolbar is two
  deliberate rows — view toggle left / timezone toggle right, then a ruled filter strip — instead of
  one wrapping row that stranded the timezone toggle; out-of-month cells use the opaque canvas
  (`bg-background`) instead of a `bg-muted/50` tint that read heavier than the real days.
- 2026-10-16: Occurrence building moved from `CalendarController::transformOccurrences()` into
  `AppActionsPostsBuildCalendarOccurrencesAction`, shared with the new portal calendar. The UI
  moved into `resources/js/components/content-calendar.tsx` (`ContentCalendar`: `baseUrl`, `postHref`,
  `multiClient`), used by `calendar/index.tsx` and `portal/calendar.tsx`; times render as HH:MM and
  phones open on the list view. Client reviewers are now redirected from `/calendar` to
  `/portal/calendar` (supersedes the 2026-09-18 role-based chip-link fix below).
- 2026-09-18: Client-portal audit fix: occurrence chips now link a `Role::ClientReviewer` viewer to
  `portal.posts.show` instead of the internal `posts.edit` editor (checked via
  `usePage<{auth:Auth}>().props.auth.user.role`), so clicking a post from the calendar takes them
  to the page with the actual approve/request-changes UI instead of a mostly-blank editor.
- 2026-09-09 (Step 0.11): Initial implementation. Added `PostPolicy::viewCalendar`,
  `CalendarController::index` (route `calendar`, `GET /calendar`), the
  `resources/js/pages/calendar/index.tsx` page (month/week/day/list views, account-local/org-timezone
  toggle, client/campaign/status/platform filters), a "Calendar" sidebar nav entry, and
  `tests/Feature/CalendarControllerTest.php` covering the dual-timezone acceptance criterion, filters,
  and client-reviewer scoping.
- 2026-09-09: Added a gradient-brand hero header above the filters/view-toggle row, replacing the
  plain `Heading` title, to match the agency-premium restyle applied across the dashboard, clients,
  and post editor pages.
