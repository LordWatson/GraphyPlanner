# Alima — Frontend Redesign Notes (session, Oct 2026)

A working companion to `.junie/brand-guidelines.md` for the current full-frontend redesign. The
brand book stays the source of truth and is **not** edited by this effort — its *fixed* items
(logo, colour values, typeface, the Alima voice) are untouchable. This file records the redesign's
art direction and the layout/interaction/component decisions made on top of that book, so the work
stays coherent page to page. Where this file and the book ever conflict on a *fixed* brand item,
the book wins; this file only ever refines the *flexible* layer (layout, interaction, component
composition, responsiveness).

## Brief

Total redesign of the frontend — every page, component and module — keeping only the **colour
palette, branding, and the hero cards** (`ChapterHero`/`HeroStat`). Everything else is open. It
must work flawlessly on PC **and** mobile, with an intuitive, effortless UX. Features already work;
this is purely UI/UX.

## Art direction — "Alima, in motion"

The existing app is a beautifully austere *printed brand book*: mono eyebrows everywhere, running
heads, hairline rules, flat spec-sheet density. The redesign keeps the Alima soul (cream canvas,
teal frame, Ink emphasis, lime/lilac/orange accents, Poppins + DM Mono, the Ink `ChapterHero`) but
shifts the feel toward a **warm, spatial, confident product**:

1. **Breathe.** More whitespace and clearer grouping; fewer hairlines, more gentle surfaces.
2. **Quieten the mono.** Keep eyebrows as deliberate accents, not a label on every element.
3. **Mobile is first-class, not an afterthought.** Thumb-reachable navigation, 44px+ targets,
   nothing that only works with a mouse.
4. **One clear primary action per view**, and an obvious "what needs me" hierarchy.
5. **Selection language is consistent everywhere:** the Deep Teal brand action (Acid Lime in dark),
   never a decorative accent, for "this is active/selected".
6. **Keep the signatures:** the Ink `ChapterHero` + Lime `HeroStat` stay the identity of every
   primary page.

## Decisions log

### Slice 1 — the application shell (done; ships on every page)

- **One nav source of truth** — `hooks/use-nav-groups.ts` resolves the role/workspace-gated nav
  model once as `{ homeHref, groups: {label, items}[] }` (studio / business / platform console).
  Both the desktop sidebar and the phone bar read it, so they can never drift.
- **Sidebar as a true Contents page** — `NavMain` renders each chapter as **icon + title with the
  chapter number as a right-aligned mono index** (collapsed: the icon alone). Keeps the Ink-on-Lime
  active pill. More scannable than bare numerals, and still on-brand (a Contents page lists titles
  against page numbers).
- **First-class phone navigation** — `components/mobile-nav.tsx`: a fixed bottom tab bar
  (`md:hidden`) with the first four primary chapters + a "More" sheet listing every destination by
  group. Active tab = Deep Teal brand action in a soft tint chip. Mirrors the client portal's bar so
  the whole app moves the same way on a phone. Content gets `pb-20 md:pb-0` to clear it.
- **Responsive workspace header** — `AppSidebarHeader`: desktop = sidebar trigger + breadcrumbs +
  Create + search pill + date + bell; phones lead with the `BrandMark` (sidebar is off-canvas there)
  and carry the account menu on the right (it lives in the sidebar footer on desktop).
- Verified: `tsc`, `vp check` (0 warnings), frontend guards.

### Slice 2 — Home (in progress)

- Home (`pages/dashboard.tsx`) is already a strong page against the book; the confident win was
  consistency — every KPI figure in the `StatStrip` is now a tap target (Unapproved → review queue,
  Scheduled → calendar), so all four behave the same and give proper touch targets on phones.
- Deeper page-level visual restructuring is paused pending a working visual loop this session
  (Chrome screenshot capture + viewport emulation are frozen), to avoid regressing a polished,
  data-dense page blind.

### Slice 3 — Post editor (done)

The guided stepper is strong; the fixes were everyday navigation friction, not a reskin:

- **Step transitions now return you to the top of the step.** The action bar is sticky at the
  *bottom*, so Back/Next previously switched the panel while leaving you scrolled past the new
  step's start. Added `goToStep` to `use-editor-navigation` (set the step + scroll the spine to the
  top of the viewport, reduced-motion aware) and wired it into the action bar (Back/Next), the step
  spine chips, the preview rail's "Review & send" nudge, and the post-save `ErrorStepSwitcher` (so a
  validation error on a hidden step is scrolled into view, not just silently selected).
- **Mobile: the active step chip stays in view.** The spine scrolls horizontally on phones, so a
  later step could sit off-screen; `StepSpine` now centres the active chip in its own scroll
  container whenever the step changes (horizontal only — never nudges the page).
- Verified: `tsc`, `vp check` (0 warnings), guards, 17 post-editor vitest tests, and a live mount +
  step-switch check.

### Slice 4 — Post editor on mobile (done; revised after user feedback)

First pass over-corrected (hid the tab bar, bar still overlapped). Root cause found by live DOM
measurement: the editor `<form>` was `display:grid` with an **implicit `auto` column + `min-width:
auto`**, so a wide child (the step spine's `min-w-fit` steps, a long caption/URL) grew the track past
the viewport; the shell's `overflow-x-clip` then cut sections off and the spine couldn't scroll.

- **Overflow (the real fix):** the form is now `grid grid-cols-1` → the column is `minmax(0,1fr)`
  (min 0), so no child can blow it past the viewport. Added `min-w-0` to the step spine `<nav>` (so
  its inner `overflow-x-auto` strip actually scrolls instead of growing), the two-column wrapper, and
  the preview `<aside>`. Verified live: injecting a 1500px child no longer grows the page width.
- **Two bottom bars:** the global tab bar is **restored** on the editor (per user), and the step
  action bar is **docked directly above it** — on phones a full-width `fixed` toolbar at
  `bottom-[calc(3.5rem+env(safe-area-inset-bottom))]` (the tab bar is 56px + safe-area), one row,
  never wrapping; the floating pill returns from `md:` up. `fixed` is safe — verified the editor has
  no transformed/containing-block ancestor. The form gets `pb-16` on mobile to clear both bars.
- The Compose step's AI buttons wrap and the long "Repurpose per platform" label shortens on small
  screens.
- **Slimmer save bar (per user):** on phones the action bar now uses `sm` controls, tighter padding,
  an icon-only Back and a minimal save state (just a tick when all saved) — a ~44px strip (was ~56px)
  — docked above the tab bar. Desktop keeps the floating pill (slightly more compact).
- **No explicit Save on autosaving drafts (per user):** the Save button is drawn only when the post
  does not autosave; idea/draft/changes_requested posts save themselves (status shows "Autosaving…",
  ⌘/Ctrl+S still forces it), so their bar is just Back · status · Next. Verified live: Idea post has
  no Save button; a waiting-client post still shows it.
- Verified: `tsc`, `vp check` (0 warnings), guards; live checks — editor mounts, navbar present on the
  route, action bar is `fixed` on mobile, and page overflow is contained. Exact two-bar pixel stacking
  still needs the user's eyes (screenshots/viewport emulation remain frozen in this session).

### Slice 5 — Review & send step overflow (done)

Same root cause as Slice 4, one level deeper. The editor's vertical-stack grids used an implicit
`auto` column, which grew to a wide child's min-content and — crucially — denied the workflow stage
track's `overflow-x-auto` a bounded width, so it expanded the whole step off a phone instead of
scrolling. Fix: `grid-cols-1` (→ `minmax(0,1fr)`, min 0) on the editor's left column (protects every
step) and on the Review step's root grid. Verified by live measurement at a 360px clamp: review grid
capped to 360, the stage track scrolls internally (326 visible / 632 scrollable), and zero elements
overflow outside a scroll container. `tsc`, `vp check` (0 warnings), guards all pass.

**General rule:** a vertical stack that can hold wide/scrolling children must be `grid-cols-1` (or
`flex-col`), never a bare `grid gap-*` — a bare grid's `auto` column grows to content and breaks
`overflow-x-auto` descendants on narrow screens.

## Rules established this session (apply to every page from here)

- Primary/index pages keep opening with `ChapterHero` + a single `HeroStat`.
- Use the Deep Teal brand action for selection/active state; reserve accents (orange/lilac/lime) for
  decoration and the hero, per the book.
- Every page must be usable one-handed on a phone: no horizontal scroll on the page itself, wide
  tables scroll inside their own panel, controls ≥ 44px, bottom bar never covered.
- Prefer grouped surfaces with calm spacing over hairline-ruled spec rows for primary content;
  reserve the spec-row (`StatStrip`) look for genuine secondary KPI strips.

## Changelog
- 2026-10-24: Created. Moved the shell/mobile-nav guidance out of `brand-guidelines.md` (reverted to
  original) into this session file, per the user's request to keep the brand book pristine.
