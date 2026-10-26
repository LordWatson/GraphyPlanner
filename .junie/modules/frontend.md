# Frontend foundation (shared primitives, http, tests)

## Purpose
The cross-cutting frontend pieces every module builds on: the shared list table, date/time and
search pickers, the one JSON `http` helper, the CI guard rails, and the frontend test harness
(Vitest + React Testing Library, Playwright journeys). Covers Steps M-1.4 (I-14.2) and M-1.5
(I-14.6) of `.junie/plans/mvp.md`; the editor-specific pieces of M-1.6 are in `posts.md`.

## Models / Tables
- None.

## Components / Hooks / Helpers
- **shadcn primitives** (`components/ui/`, Alima-restyled: `rounded-xl`, `shadow-paper`, mono
  uppercase headers): `table`, `popover`, `command` (cmdk), `calendar` (react-day-picker 9, weeks
  start Monday), `progress`.
- **`DataTable`** (`components/data-table.tsx`, TanStack Table 8): server mode (pass
  `pagination` + `onPageChange`, and `sort` + `onSortChange`; nothing is sorted or sliced in the
  browser) or client mode (`pageSize` pages locally). Sorting is opt-in per column
  (`enableSorting: true`) so a server list only offers sorts its endpoint supports. Column meta:
  `label` (visibility menu), `hideBelow` (`sm`/`md`/`lg`), `align: 'right'`, `className`.
  `bulkActions` adds a selection column and an Ink bulk bar; `rowHref` makes rows open a page
  (keep a real link in the main cell for keyboards); `storageKey` remembers hidden columns per
  browser (`localStorage`, try/catch). Cell/header renderers are *called*, not mounted as
  components, so inline column definitions don't remount every cell each render (they mustn't use
  hooks). Used by: clients roster + archive, client Posts tab, invoices ledger (+ Billing tab),
  staff roster + invitations, super-admin organizations, analytics and super-admin saved reports.
- **`DatePicker`** (`components/date-picker.tsx`): `YYYY-MM-DD` strings only (no timezone shift),
  controlled or uncontrolled (`defaultValue` + `name` → hidden input for `<Form>`), `min`/`max`,
  `clearable`. **`TimeField`**: the shared `HH:MM` field (native time control kept for keyboard /
  screen readers / phones). **`DateTimePicker`**: date + time labelled with the account's clock,
  plus "Your time: …" when the viewer's timezone differs. No native `type="date"`/`"time"` input
  is used outside these components.
- **`Combobox`** (`components/combobox.tsx`): searchable select (Popover + Command) for long lists.
- **`MultiCombobox`** (`components/multi-combobox.tsx`): the multi-select sibling — same Popover + Command stack, toggles keep the popover open, trigger shows an "N selected" count, optional `max` disables unselected options once the cap is reached. Reuses `Combobox`'s `ComboboxOption` type. Used by `/campaigns/compare`.
- Pickers open their popover as `modal`: inside a modal `Dialog`, a non-modal popover inherits the
  dialog's outside `pointer-events: none` and can't be clicked (found by the E2E journey).
- **`http`** (`lib/http.ts`): `get/post/put/patch/delete`; same-origin cookies + `X-XSRF-TOKEN`;
  JSON bodies or `FormData` (multipart); never throws — `{ ok, data }` or `{ ok: false, status,
  message, errors, aborted }` with readable messages (422 first error, 413, 419, 429, 5xx,
  offline); `toastOnError`; `rateLimitMessage`; `firstErrors()` for `InputError`. `lib/campaigns`
  `postJson`/`getJson` wrap it with the AI rate-limit wording.
- **`confirmWithPassword()`** (`components/confirm-dialog.tsx`): the shared confirm dialog with a
  password field, for irreversible actions the server re-checks (client purge).
- **`useKeyboardShortcut`** (`hooks/use-keyboard-shortcut.ts`): one window shortcut; `mod` = ⌘ or
  Ctrl; ignores typing in fields unless `whileTyping`.
- **`lib/timezones.ts`**: `zonedToInstant`, `instantToZoned`, `convertSlot`, `offsetMinutes`,
  `formatInstant`, `viewerTimeZone`, `cityOf` — `Intl` only, DST-gap times resolve forward.
- **`SetupChecklist`** (`components/setup-checklist.tsx`) — ux-overhaul. The shared "get set up"
  first-run card: takes `SetupStep[]` (`{title, hint, cta, icon, href, done, visible?, method?}`),
  marks the first incomplete visible step as the next move, and renders nothing once every visible
  step is done. Used by `clients/client-setup-checklist.tsx` (agency) and
  `business/brand-setup-checklist.tsx` (creator). The "done" signals come from the server
  (`BuildClientPageHeaderAction` / `GetBusinessDashboardAction`); `BrandBrain::isFilled()` backs the
  "brand" step for both.
- **Shell navigation** — one source of truth, two shells. **`useNavGroups`**
  (`hooks/use-nav-groups.ts`) resolves the role/workspace-gated nav model (studio / business /
  platform console) once as `{ homeHref, groups: {label, items}[] }`; both the desktop teal sidebar
  (`components/app-sidebar.tsx` → `NavMain`) and the phone bottom bar (`components/mobile-nav.tsx`)
  read it, so they can never disagree on who sees which chapter. `NavMain` renders each chapter as a
  Contents-page row — icon + title with the chapter number as a right-aligned mono index, collapsing
  to the icon alone. **`MobileNav`** is a fixed bottom tab bar (`md:hidden`): the first four primary
  chapters plus a "More" sheet listing every destination by group; the active tab uses the Deep Teal
  brand action in a soft tint chip (the sidebar's selection language), not a decorative accent.
  `AppSidebarHeader` is responsive — desktop shows the sidebar trigger + breadcrumbs, phones lead
  with the `BrandMark` (the sidebar is off-canvas there) and carry the account menu on the right
  (it lives in the sidebar footer on desktop). Content gets `pb-20 md:pb-0` so it clears the bar.
- **`useCreateActions`** (`lib/create-actions.ts`) + **`CreateMenu`** (`components/create-menu.tsx`)
  — MOVE 4 (ux-overhaul), "one Create". `useCreateActions` is the single, role/workspace-gated set
  of "make something new" actions (New post / Add a client / Build a report; business New post;
  super-admin Onboard an org / Build a report). `CreateMenu` renders it as the always-visible "New"
  button in `AppSidebarHeader`, and the ⌘K command palette's Create group reads the same hook, so
  the two never drift. Hidden when the viewer can create nothing.

## Guard rails (`scripts/frontend-guards.mjs`, part of `npm run check`)
- No raw `fetch(` outside `lib/http.ts`.
- No post-editor file (`pages/posts/edit.tsx`, `components/post-editor/**`) over 300 lines.

## Tests
- **Vitest + RTL** (`npm test`, config in `vite.config.ts` `test` block, jsdom 26, setup in
  `resources/js/test/setup.ts`): `lib/http.test.ts`, `lib/timezones.test.ts`,
  `lib/post-workflow.test.ts`, `lib/grid-layout.test.ts`, `components/data-table.test.tsx`,
  `components/date-time-picker.test.tsx`, `components/post-editor/readiness-checklist.test.tsx`,
  `components/post-editor/editor/editor-state.test.ts`,
  `components/post-editor/editor/editor-stepper.test.ts` (the guided-stepper step helpers).
- **Playwright** (`npm run test:e2e` after `npm run build`; `playwright.config.ts`, specs in
  `tests/e2e`): `signup-to-scheduled.spec.ts` — sign up → verify email (link read from the JSON
  mail log) → client → LinkedIn channel → post with a date/time → start drafting → internal
  review → Owner approves without the client → schedule. `tests/e2e/support/server.mjs` migrates a
  fresh `database/e2e.sqlite` and serves `public/` with PHP's built-in server (not `artisan
  serve`, which would drop env vars also in `.env`); `support/env.mjs` holds the isolated
  environment (SQLite, file cache/sessions, `MAIL_MAILER=log`, JSON log at
  `storage/logs/e2e.log`, database queue never worked). `E2E_PHP` picks the PHP binary.
- CI runs both on every PR (`.github/workflows/tests.yml`).

## Known Gaps / Open Questions
- Only the first of the four I-14.6 journeys exists; the portal approval, publish-failure retry
  and invoice → paid journeys land with M-4 / M-6 / M-11 (per the MVP note on M-1.5).
- `DataTable` has no virtualisation; lists stay server-paginated (25 per page by default).
- Playwright runs Chromium only; mobile viewports and axe checks come with M-6.6 / M-11.1.

## Changelog
- 2026-10-24: `ChapterHero` consistency — the campaigns hub (`pages/campaigns/index.tsx`), the grids
  hub (`pages/grids/index.tsx`) and the settings area (`layouts/settings/layout.tsx`, which opens
  every settings sub-page) now open with the Ink `ChapterHero` instead of `PageHeader`, matching every
  other primary chapter/index page. Grids leads with a `HeroStat` (total grid plans); settings and the
  campaigns hub omit the aside (settings has no stable count; campaigns' stats are `Deferred`), keeping
  the hero's peek as the one graphic. `NewCampaignDialog`/`NewGridDialog` gained optional
  `triggerVariant`/`triggerSize` props so their trigger can render as a `spark` CTA on Ink (default
  unchanged). Verified: `tsc`, `vp check` (0 warnings), frontend guards.
- 2026-10-24: Frontend redesign, slice 1 — the application shell. Extracted the sidebar's nav model
  into a shared `useNavGroups` hook; reworked `NavMain` to a Contents-page row (icon + title + mono
  index); added `MobileNav` (a first-class phone bottom tab bar + "More" sheet for the studio, which
  previously only had a slide-over) wired into `app-sidebar-layout`; made `AppSidebarHeader`
  responsive (brand mark + account menu on phones). Keeps the Alima palette, logo and selection
  language. Verified: `tsc`, `vp check` (0 warnings), frontend guards.
- 2026-10-24: UX overhaul MOVE 4 (`.junie/plans/ux-overhaul.md`) — "one Create": extracted
  `useCreateActions` (`lib/create-actions.ts`), added the always-visible `CreateMenu` "New" button
  to the workspace header, and refactored the command palette's Create group to consume the same
  hook. Verified: `tsc`, `vp check`, guards, 130 vitest tests.
- 2026-10-20: Created. Steps M-1.4 (primitives, `DataTable` on every list page, `http` helper with a
  CI grep guard) and M-1.5 (Vitest + RTL, Playwright "sign up → first post scheduled" in CI).
