# Alima — Frontend Brand Guidelines

This document defines the visual and interaction language for the Alima Planner frontend (Laravel +
Inertia + React), the transactional emails and the invoice PDF. It is the product translation of the
**Alima Brand Guidelines, Edition 01 (October 2026)** — the source book and artwork live in
`.claude/docs/brand/` (`Alima — Brand Guidelines.pdf`, and `Alima.pdf`, which holds the vector
masters). When this file and the book disagree on anything other than product-specific mechanics,
the book wins; update this file.

**The idea in one line:** *Marketing brighter together.* Lead with Cloud Cream; Deep Teal and
Midnight Ink carry structure; four accents (Signal Orange, Electric Blue, Soft Lilac, Acid Lime) are
seasoning — one hero accent per layout. One geometric family (Poppins), used with conviction.

Read this file before building or restyling any page, component, email or PDF. Pair it with
`.junie/plans/graphy-development-steps.md` (§9 UI contract) for feature-specific layout requirements.

## 0. Fixed, flexible, in doubt (book §00)

- **Fixed:** logo artwork, colour values, typeface, clear space, minimum sizes. Never redraw or
  approximate.
- **Flexible:** layout, colour pairings (from the approved list), pattern crops, the peek.
- **In doubt:** fewer colours, more cream, a bigger logo.

## 1. Design principles

1. **Lead with cream (≈50%).** The workspace is Cloud Cream `background` with `card` (bg.surface)
   panels. Teal ≈20%, Ink ≈15%, accents ≈15% combined.
2. **Teal frames, Ink emphasises.** The app shell is the shadcn sidebar `inset` variant: a **Deep
   Teal** frame (`sidebar`, cream type — colourway "Cream / Teal") with the cream workspace sheet
   (`SidebarInset`, `rounded-2xl`, `shadow-paper`) on it. When something needs more weight than a
   card — the hero KPI, a studio note to a client, a closing CTA — invert it to `bg-ink`.
3. **Type does the talking.** Big Poppins SemiBold headlines in sentence case, tight leading,
   negative tracking, statements ending in a full stop. DM Mono uppercase eyebrows read like a
   printed spec sheet (`OVERVIEW — FRIDAY, OCTOBER 16`).
4. **Signal Orange is decorative in UI — never a status colour** (book §7.1). It appears as the one
   rounded orange dash per layout (`<Eyebrow dash>`), the orange full stop / accent word on a
   marketing hero, and in patterns or the peek. Never a button, never a surface, never a status.
5. **Calm density where it matters.** Data screens (tables, lists, calendar, editor) stay flat:
   hairline borders, `hover:bg-muted/50` rows, no patterns, no peeks, no spectrum. Expression is
   reserved for page headers, empty states, auth, marketing, the client portal and public links —
   this now includes the `ChapterHero` that opens every primary chapter/index page (its peek is the
   page's one graphic), with the tables, panels and filters below it kept flat as ever.
6. **Status is always legible.** Every status renders through `<StatusPill>` — a tinted pill with a
   solid tone dot **and** a text label. Never colour alone.
7. **One hero accent, one graphic** per layout (book §9.2 check 06): one pattern *or* one peek *or*
   the spectrum — never two, and never squiggle with confetti.
8. **The app is the book.** The studio is laid out like the guidelines themselves: numbered
   chapters (`lib/chapters.ts`) in a Contents-style sidebar, a mono running head over an ink
   hairline on every page, chapter openers (`ChapterHero`) for landing pages, numeral spec rows
   (`StatStrip`) for KPIs, and ruled rows for triage. Reach for these before inventing a layout.

## 2. Design system foundation

- **Component library:** shadcn/ui (Radix) in `resources/js/components/ui`, restyled to Alima
  (§6). Compose these; don't hand-roll primitives.
- **House components** (use these before writing layout markup):
  | Component | File | Use |
  |---|---|---|
  | `Page` | `components/page.tsx` | Page container: 1400 max width, `gap-8`, responsive padding. `width="narrow"` for detail/form pages. |
  | `PageHeader` | `components/page.tsx` | Every internal page's header. Opens with the chapter `RunningHead` (resolved from the URL); `eyebrow` sits on its right. `title` (ReactNode, `<em>` = accent word) at 52px, `description`, `meta`, `actions`. Outside a chapter (client portal) the eyebrow falls back to the orange-dash `Eyebrow`. |
  | `ChapterHero` | `components/page.tsx` | The chapter opener: Ink block, inverse running head, giant headline with a Lime `<em>`, the one orange dash, `actions` (`spark` + `on-ink` only — never `default`/`outline` on Ink), an `aside` hero figure, and the lilac peek. One per page, in place of `PageHeader`; it is the page's one graphic. **It is the standard opener for every primary chapter/index page** — the studio's Overview, Calendar, Content, Clients, Invoices, Analytics and Staff, the super-admin console and the business workspace, plus the portal — each leading with a `HeroStat` figure. `PageHeader` stays for detail, form, sub- and archive pages. |
  | `HeroStat` | `components/page.tsx` | The giant Acid Lime figure inside a `ChapterHero` `aside`: a mono `eyebrow`, one Poppins Bold `value` numeral, a `caption`, an optional `icon` and `href` (makes the whole figure a link with a trailing arrow). Every primary page leads with its single most telling number — open decisions, posts in the pipeline, brands on the books, invoices awaiting payment. Pad counts to two digits (`padStart(2, '0')`); omit the aside when a page has no stable count (deferred or client-loaded data). |
  | `RunningHead` / `ChapterMark` | `components/page.tsx` | The book's page head (mono line over an ink hairline) and the chapter swatch + number (`■ 04 — Clients`). `useChapter()` returns the current chapter. |
  | `StatStrip` | `components/page.tsx` | The book's numeral spec row (§2.5): figures between vertical hairlines under an ink rule — mono label, 64px Poppins Bold numeral, mono caption. `render` wraps a cell in a link/anchor. 2×2 on mobile. Prefer it to a row of `Stat` cards for secondary KPIs. |
  | `Eyebrow` | `components/page.tsx` | DM Mono label. `dash` adds the rounded orange rule — once per layout. |
  | `Panel` | `components/page.tsx` | Surface section (24px radius, `shadow-sm`) with optional `eyebrow`/`title`/`icon`/`actions` header. Icons sit in a solid tint circle. |
  | `Stat` | `components/page.tsx` | KPI tile, Poppins Bold numeral. `tone="ink"` for the one hero stat per row; `tone="accent"` = teal block. |
  | `EmptyState` | `components/page.tsx` | Every empty list: lilac icon circle, one short display line ending in a full stop, one sentence, one action. |
  | `StatusPill` | `components/status-pill.tsx` | Every status/health/role badge. |
  | `BrandMark` | `components/brand-mark.tsx` | The logo. `wordmark` = horizontal lock-up in `text-logo`; otherwise the app-icon tile (cream mark on teal). |
  | `ArtworkSvg`, `Snout` | `components/brand-mark.tsx` | Render master artwork / the snout (list markers, seals, loaders, sign-offs). |
  | `BrandPattern`, `Peek`, `BrandPoster` | `components/brand-poster.tsx` | Squiggle/confetti patterns in approved pairs; the peek; the auth/marketing poster. Expressive surfaces only. |
  | `PublicShell` / `PublicTitle` | `components/public-shell.tsx` | Token-link pages (client review, invites). `width`: `md` (forms), `lg`, `xl` (two-column pages — the review). |
  | `Heading` | `components/heading.tsx` | `variant="small"` = a section within a page (settings, staff): ruled spec header, ink hairline over a 17px title. Don't add your own border above it. |
  | `CommandPaletteProvider` | `components/command-palette.tsx` | The studio's ⌘K / Ctrl+K palette (chapters, create, theme), mounted once by the sidebar layout; `useCommandPalette().open()` opens it. Add new destinations there, gated like the sidebar. |
- **Chapters:** `resources/js/lib/chapters.ts` is the single source of truth for module numbering
  (book §00 Contents): 01 Overview · 02 Needs attention · 03 My work · 04 Calendar · 05 Clients (incl.
  campaigns, posts, AI pages) · 06 Invoices · 07 Analytics · 08 Staff · 09 Settings, each with a swatch, icon, one-line summary
  and URL prefixes (`/preview` maps to the same chapter). The sidebar, `PageHeader`'s running head
  and the command palette all read it — add a module there, never hard-code a number. Swatches are
  chapter identity only, never status. Numbers are fixed per chapter, so a role that can't see a
  chapter simply skips its number.
- **Artwork:** `components/brand/artwork.ts` holds the mark, wordmark, lock-up, snout, squiggle and
  confetti path data, **lifted verbatim from the vector masters in `.claude/docs/brand/Alima.pdf`**.
  It is generated — never hand-edit or redraw it. Static exports for email/PDF/favicons live in
  `public/brand/` (`alima_logo_{teal,cream}.svg`, `…@2x.png`, `alima_mark_{teal,cream}.svg`) and
  `public/favicon.*` (16/32/48 px ICO, SVG and 180 px apple-touch icon, all cream mark on teal).
- **Status mapping:** `resources/js/lib/status.ts` is the single source of truth (`statusMap`,
  `resolveStatus`, `toneBadgeVariant`, `toneTextClass`, `toneBgClass`). Pages never declare their own
  status colour records.
- **Dates:** never render API dates raw (`2026-10-07`, `2026-10-17 09:00:00`). Use
  `resources/js/lib/dates.ts`: `formatDate` ("7 Oct 2026"), `formatDateRange` ("7 Oct → 14 Nov
  2026"), `formatSchedule` (a channel slot in its own local time, "Sat 17 Oct · 09:00") and
  `formatDateTime` (a moment, viewer-local, "16 Oct 2026, 14:05"). Calendar dates are parsed as
  local dates so they never shift a day. Form inputs keep the raw value.
- **Styling:** Tailwind v4, tokens in `resources/css/app.css`. Semantic classes only (`bg-card`,
  `text-muted-foreground`, `bg-ink`, `text-primary`, `bg-lime`…). No raw hex or Tailwind palette
  colours (`amber-500`, `neutral-200`) in components.
- **Icons:** `lucide-react` only — it is Alima's base set (book §5.6): 24px grid, 2px stroke, round
  caps, one colour, always paired with a label in UI. Feature icons sit in a solid circle at 2× the
  icon size.
- **Dark mode:** `.dark` rebuilds every token on Midnight Ink with cream type. Check every change in
  both modes.

## 3. Colour

### 3.1 Masters (book §3.1 — fixed values)

| Name | Hex | Role |
|---|---|---|
| Deep Teal | `#205557` | Primary — structure, brand actions, links, the frame |
| Midnight Ink | `#181723` | Primary — text, main buttons, emphasis blocks |
| Cloud Cream | `#F5F2E8` | Primary — the canvas |
| Signal Orange | `#F03D2A` | Accent — decorative only (dash, hero full stop, patterns, peek) |
| Electric Blue | `#4E91D4` | Accent — charts, focus (as `#2C6CAD`), tags |
| Soft Lilac | `#C9A4D8` | Accent — empty states, peek, patterns, tags |
| Acid Lime | `#D2E842` | Accent — active nav icon, CTA on Ink, dark-mode brand, tags |

Tints (book §3.7) are mixed with **cream, not white**, so they stay warm. Never tint the logo.

### 3.2 Semantic tokens (book §7.1 → `app.css`)

| Token | Light | Dark | Book token / note |
|---|---|---|---|
| `background` | `#F5F2E8` | `#181723` | bg.canvas |
| `card` / `popover` | `#FBFAF6` | `#201F2C` / `#24232F` | bg.surface |
| `foreground` | `#181723` | `#F5F2E8` | text.primary · 15.8:1 |
| `muted-foreground` | `#5F5D6B` | `#AEACB5` | between text.secondary/muted, ≥4.8:1 on every surface |
| `primary` | `#205557` | `#D2E842` | action.brand (Teal on Ink fails, so dark = Lime on Ink, 13:1) |
| `primary-hover` | `#173F41` | `#E0EC84` | action.brand.hover |
| `ink` | `#181723` | `#0F0E17` | bg.inverse |
| `input` | `#8C8A8F` | `#6B6977` | border.control · 3.1:1 (2px borders) |
| `ring` | `#2C6CAD` | `#6FA4D8` | focus.ring · 3px |
| `destructive` | `#B52A1B` | `#F28576` | status.error · 5.7:1 |
| `warning` | `#8A5A00` | `#E3B55C` | status.warning · 5.3:1 |
| `success` | `#276B43` | `#7FC79A` | status.success · 5.7:1 |
| `info` | `#2C6CAD` | `#91B8DC` | Electric Blue at text contrast |
| `accent` | `#E4E9E1` | `#23393B` | Deep Teal 20 tint — menus, icon circles, soft highlights |
| `logo` | `#205557` | `#F5F2E8` | Teal on Cream / Cream on Ink colourways |
| `sidebar*` | teal frame, cream type, lime active icon | darker teal | colourway 03 / "Teal / Lime" 6.2:1 |
| `signal` `lilac` `lime` `electric` `teal` `cream` | masters | masters | decorative palette utilities |
| `chart-1…5` | teal, blue, lilac, orange, lime | lime, blue 80, lilac, orange 80, teal 40 | see §3.4 |

### 3.3 Approved pairings (book §2.6, §3.6)

Only these pairs carry text or the logo: Teal/Cream, Ink/Cream (either way), Cream on Teal, Ink on
Lime, Ink on Lilac, Ink on Blue, Cream/Orange at **24px+ only**. **Never Teal on Ink** (2.1:1) — use
Lime or Cream inside Ink blocks (`text-lime`, `variant="spark"` buttons).

### 3.4 Status tones (`lib/status.ts`)

- **Post:** idea/archived → muted · draft → neutral · internal_review/waiting_client → info ·
  changes_requested/publishing → warning · approved/published → success · scheduled → brand (teal) ·
  failed → danger.
- **Health:** green → success · amber → warning · red → danger (labels "Green/Amber/Red").
- **Client:** active → success · paused → warning · offboarding → neutral · archived → muted.
- **Campaign:** planning → neutral · active → success · completed → info · archived → muted.
- **Invoice:** draft → neutral · sent → info · paid → success · overdue → danger · void → muted.
- **Connection:** connected → success · token_expired → warning · not_connected → neutral.
- **Invitation:** pending → info · accepted → success · expired → neutral · revoked → muted.
- **Role:** owner/super_admin → brand · client_reviewer → info · viewer → muted · others → neutral.
- **Organization** (super admin console): active → success · suspended → danger · preview fixture → muted.

Extend the map in `lib/status.ts`; never inline a one-off colour.

### 3.5 Charts

- Series read from `chart-*`. Two-series charts use `chart-1` (Deep Teal; Lime in dark) and `chart-2`
  (Electric Blue). They differ strongly in lightness as well as hue in both modes, so they stay
  distinct for colour-blind readers. Re-validate before adding a third series.
- Status-meaning series (published vs failed) use `success` / `destructive`, with a dashed stroke on
  the failure series.
- 2px lines, one light area fill at most, dashed `border`-coloured horizontal grid, DM Mono 11px axis
  ticks, legend top-right, popover-styled tooltip (`shadow-md`). Never two y-axes.

### 3.6 The spectrum (book §3.8)

`.bg-spectrum`: fixed order Ink → Orange → Blue → Lilac → Lime → Teal → Cream, 90°. Hero bands,
transitions and loaders only (the `PublicShell` top band). Never behind text, never as a type fill,
never inside the logo, never on data screens.

## 4. Typography

| Family | Token | Job |
|---|---|---|
| **Poppins** (300–700) | `font-sans`, `font-display` | Everything. `font-display` = SemiBold 600, −2% tracking, for headlines and numerals. |
| **DM Mono** (400/500) | `font-mono`, `.eyebrow` | Spec-sheet labels, times, IDs, counts, invoice numbers, breadcrumb separators. |

- **Weights (book §4.2), two per layout at most — usually SemiBold over Regular:** 300 Light = large
  pull quotes only (studio notes to clients) · 400 Regular = body and captions · 500 Medium = UI
  labels, lead paragraphs · 600 SemiBold = headlines, buttons · 700 Bold = big numbers (`Stat`,
  money totals, calendar dates).
- **Scale:**
  - Marketing hero — `clamp(3.5rem, 8vw, 6rem)`, `leading-[1.02]` (book Display 96/56).
  - Page title (`PageHeader`) — `text-[2.25rem] sm:text-[2.75rem]`, `leading-[1.05]`.
  - Panel / section header — `text-[15px] font-semibold`.
  - Body — `text-sm` (data) / `text-[15px]` (descriptions). Marketing body 18px.
  - Eyebrow — `.eyebrow` (DM Mono 11px, `tracking-[0.1em]`, uppercase), `text-muted-foreground`
    (or `text-primary` on marketing).
  - Meta / helper — `text-xs text-muted-foreground`.
- **Setting type (book §4.4):** sentence case (caps only in eyebrows) · flush left · tight headlines ·
  statements end with a full stop, sometimes orange on marketing heroes · one rounded orange dash
  per layout · 45–75 characters per line, no widows (`text-balance` / `text-pretty`).
- **Accent word:** one `<em>` per headline, rendered **upright** in `text-primary` by `PageHeader`
  / `PublicTitle`. One word, maybe two.
- **Numerals:** `tabular-nums` for anything columnar; Poppins Bold for hero figures, DM Mono for
  times/IDs.
- **Arabic:** if Arabic copy is ever introduced, add Tajawal (Bold for headlines, Regular for text),
  set it 10–15% larger, mirror layouts (never the logo), and never letter-space it.

## 5. Layout & shape

- **Shell:** `AppSidebar` (`variant="inset"`, collapsible to icons) on the teal frame. The sidebar
  logo is the cream lock-up (collapses to the mark). It reads like the book's Contents page: groups
  labelled with eyebrows ("Studio", "Team"), each item a mono chapter number + title (collapsed:
  the chapter icon), and the active chapter an **Ink-on-Lime pill**. Its foot carries the tonal
  peek (teal on teal) rising over a hairline, the tagline as an eyebrow, then the account card —
  never text over the peek. The sticky workspace header (64px) is translucent cream with backdrop
  blur: sidebar trigger · breadcrumbs (`/` mono separators) · the "Search or jump to… ⌘K" pill ·
  mono date stamp · bell.
- **Grid & spacing (book §7.5):** 4px base. Marketing pages follow the book's web grid — 1280 max,
  80px desktop margins, 40 tablet, 20 mobile; header 88px with a 160px-wide logo. App pages use
  `Page` (1360 max).
- **Page rhythm:** `Page` → `PageHeader` (running head on top) or `ChapterHero` → groups at `gap-5`
  inside, `gap-8` between. Asymmetric grids (`lg:grid-cols-[1.45fr_1fr]`) beat equal columns. For
  triage/index pages, ruled rows (ink `border-t`, label column left at ~300px, content right — the
  book's "Purpose" spread) beat a grid of equal cards; collapse empty rows to one line.
- **Radii (book §7.2):** inputs/selects 8px (`rounded-lg`) · nested cards 16px (`rounded-xl`) ·
  panels, cards, dialogs, the workspace sheet 24px (`rounded-2xl`) · buttons, chips, tabs, toggles,
  nav items: pill (`rounded-full`). Label tags (`lime`/`lilac`/`electric` badges) 3px.
- **Elevation (book §7.2):** `shadow-sm` (0 1 2 · 6% Ink) on resting cards/panels; `shadow-md`
  (0 8 24 · 10% Ink, also `shadow-paper`) for things that float — the sheet, menus, popovers, the
  product vignette, button hover. No hover-lift on cards.
- **Lists/tables:** zebra-free, `divide-border/70` rows, `hover:bg-muted/50`. Roster lists may lead
  with a mono index (`01`, `02`).

## 6. Components & patterns

- **Buttons** (`ui/button.tsx`, book §7.3 — pills, SemiBold, heights 40 `default` / 48 `lg` / 56 `xl`,
  32 `sm`): `default` = **Primary**, Midnight Ink (cream in dark mode) — one per view ·
  `secondary`/`brand` = **Brand**, Deep Teal (Lime in dark) · `outline` = **Secondary**, 1.5px ink
  outline on paper · `link` = teal underlined · `spark` = Acid Lime with ink text, only as the CTA
  inside an Ink block or on a marketing hero · `on-ink` = cream hairline secondary beside `spark`
  inside an Ink block · `ghost` · `destructive` (always behind a confirm
  dialog). States: hover lifts to `shadow-md`, pressed darkens and `translate-y-px`, disabled is a
  stone fill, focus is the blue 3px ring with offset.
- **Inputs (book §7.4):** 8px radius, **2px** `border-input`, `bg-card`, 40px tall; hover darkens the
  border; focus = blue border + soft blue ring; invalid = `border-destructive` and a red helper line
  ("Enter a number, e.g. 5000").
- **Selection:** toggles, toggle groups, segmented filters and the appearance switcher select with a
  **teal pill** (`bg-primary text-primary-foreground`) — book §7.4 B. Checkboxes are 18px, 2px border,
  teal when checked.
- **Badges:** tinted (`/10`) background + matching text + soft border, pill. `lime` / `lilac` /
  `electric` = Alima label tags (mono caps, ink text). Use `StatusPill` for anything stateful.
- **Tabs:** segmented pills — active = card on a muted track.
- **Dialogs/Sheets:** `rounded-2xl` surface, Poppins SemiBold `DialogTitle`, Ink/55 overlay with a
  light blur.
- **Tooltips:** Ink.
- **Confirmations:** never native browser dialogs (`window.confirm/alert/prompt`, `beforeunload`
  prompts). Use `confirmDialog({ title, description, confirmLabel, tone })` from
  `components/confirm-dialog.tsx` — it returns a `Promise<boolean>` and renders through the single
  `<ConfirmDialogHost />` mounted in `app.tsx`. Use `tone: 'destructive'` for discard/leave/delete.
- **Empty states:** `EmptyState` — lilac circle behind the icon, one short line with a full stop
  ("All clear.", "A quiet week."), one sentence, one action.
- **Calendar:** hairline `gap-px` grid on `bg-border`; mono day numbers, today in a teal circle;
  chips carry a 3px tone rule + mono time + tone-coloured status text + client name.
- **Loading:** skeletons shaped like the final layout for fetches >300ms; spinners only inside
  buttons. The snout may be used as a loader mark on expressive surfaces.
- **Toasts:** short, action-oriented copy.

### 6.1 Brand elements (book §5)

- **The snout** (`<Snout>`): list markers, seals, loaders, sign-offs. Min 12px; any approved pair at
  3:1; never next to the full mark, never rotated.
- **Patterns** (`<BrandPattern>`): squiggle pairs `lilac-orange`, `ink-teal`, `cream-lime`; confetti
  pairs `cream-ink`, `teal-lilac`. Full-bleed panels, crop freely, text only on a solid plate beside
  or above, never three colours, never mixed in one layout. Auth/marketing/portal hero surfaces only.
- **The peek** (`<Peek>`): the panda scaled up and cropped by its frame inside a
  `relative overflow-hidden` container — both eyes in frame, bleeding off one or two **frame edges**
  (give a floating hero a bottom rule to peek over), one colour, one per layout, at least 3× any logo
  nearby.
- **Imagery (book §5.7):** real people, real work, real light — candid, warm, one palette colour in
  frame, room for type, square crops. Avoid handshakes, pointing at screens, heavy filters, off-palette
  duotones.

## 7. Logo (book §2)

- Always via `BrandMark` / `ArtworkSvg` (or `public/brand/*` outside React). Never retype the
  wordmark, crop the ears, turn the pupils, stretch, rotate, outline, add effects or gradients, or
  animate it without an approved motion file.
- **Lock-ups:** horizontal (default — web, decks, letterhead), stacked (square/tall formats), mark
  (avatars, app icons, once the name is known), wordmark (tight spaces / panda nearby).
- **Colourways:** Teal/Cream (primary), Cream/Ink, Cream/Teal, Ink/Lime, Ink/Lilac, Ink/Blue,
  Cream/Orange, Ink/Cream. **Never Teal on Ink.** On patterns use a solid plate; on photos a calm
  area or a 50% Ink scrim.
- **Clear space:** X = the height of the wordmark's "a" (0.42 × logo height) on every side.
- **Minimum sizes:** horizontal 120px wide · stacked 64px · wordmark 80px · mark 24px. Favicons use
  the 16px teal tile.

## 8. Client-facing surfaces

- **Client review (`/review/:token`) & invites:** `PublicShell` — no navigation, a spectrum band at
  the top, the Alima lock-up + the client's name chip, big `PublicTitle`. The review is two columns
  on desktop (`width="xl"`): the post is the hero, rendered as it will look per channel through
  `SocialPostPreview` (channel tabs when there are several), beside a sticky column with the
  studio's message as an Ink pull-quote (lime quote mark, Poppins Light), a ruled "Going out"
  schedule (`formatSchedule`), and exactly two decisions, 56px tall and thumb-friendly: **Approve**
  (solid `success`) and **Request changes** (outline, destructive-tinted), with the note field
  *above* them. Mobile stacks note → post → schedule → decision.
- **Error pages (`resources/views/errors`):** Blade, not React — inline CSS and `public/brand`
  artwork so they render when the app has failed. An Ink spread: running head (cream lock-up +
  "Error 404"), the code as a giant Lime numeral, a voice-led headline ("This page wandered off.",
  "Not your page to see.", "That page went stale.", "Something broke on our side."), the orange dash,
  one line, a Lime CTA home (the studio when signed in) beside a cream "Go back", and the cream peek.
  Add a code by extending `errors.layout`.
- **Client portal (`/portal/*`):** a standalone, **mobile-first** experience — a client reviewer never
  sees the teal sidebar or any internal page (internal routes redirect them in; `AppLayout` renders the
  portal shell for them on Settings). A client typically arrives from a WhatsApp notification on their
  phone, so the shell is app-like. `ClientPortalLayout` (resolved for `portal/*` in `app.tsx`; pages
  don't self-wrap) opens with a spectrum hairline, then a sticky translucent header that **leads with
  the managing agency's name** under a "Your agency" eyebrow beside the Alima `BrandMark` tile — the
  client must see who their agency is on every page (the agency name comes from the shared `portal.agency`
  prop, set in `HandleInertiaRequests`). The client's own name sits as a chip (sm+), with the bell and
  account menu. Navigation is a **fixed bottom tab bar on phones** (Overview · Posts · Calendar ·
  Invoices · More, the "More" tab opening a bottom `Sheet` with every destination) and the **top pill nav
  on desktop** (`md:`), active = Ink; "Posts" carries the pending-approvals count. Primary portal pages
  open with `ChapterHero` + `HeroStat` like the studio; read-only detail/profile pages (Company, a post,
  an invoice) keep `PageHeader`. Pages supply padding via `ShellPaddedContext` and get no chapter running
  head — chapters belong to the studio.
- **Portal voice:** speak to the client — "Your posts", "Welcome back, Rae.", post headlines by state
  ("Ready for your *review*.", "Back with the *studio*.", "This one is *live*."). Approving always
  leads: the overview's Ink `ChapterHero` carries the waiting count as its Lime `HeroStat` numeral and a
  `spark` "Start reviewing" CTA (the list sits below on paper), a "Needs your approval" group
  atop the posts list, and the decision card beside the post preview.
- No internal-only fields, statuses (idea/draft/internal review), or other clients, ever.
- **Auth (`layouts/auth/auth-simple-layout.tsx`):** the book's cover. The left panel is Acid Lime
  with an ink running head ("Alima Planner · Edition 01 — year · v1.0"), the tagline in Poppins
  SemiBold, the four pillars as an eyebrow, and the wordmark set across the panel's foot (Ink/Lime
  colourway — it is the panel's only logo). The form side is cream with an orange-dash eyebrow, a
  voice-led title ("Welcome back.") and a mono sign-off footer. Mobile shows the lock-up and form only.
  Login alone opts in (`peek: true` on `Login.layout`) to a small lilac peek bleeding off the form
  side's bottom-right corner, `xl` and up only so it never sits under the footer or the form.

## 9. Email & PDF (book §7.8, §8)

- **Emails** (`resources/views/emails/*`): quiet, consistent, unmistakably ours. Cream canvas, a
  surface card, a Deep Teal header band carrying the cream lock-up as a hosted 2× PNG
  (`public/brand/alima_logo_cream@2x.png`, displayed at 120px), Poppins with **Arial live-text
  fallback**, an Ink pill CTA, teal links, a lilac-tint studio note. No banners or quotes in footers.
  Footer: "© {year} Alima. Marketing brighter together."
- **Invoice PDF** (`resources/views/pdf/invoice.blade.php`, dompdf): bundled Poppins Regular/SemiBold
  and DM Mono TTFs in `resources/fonts/pdf`; cream paper, Ink header band with the master mark on a
  teal tile, Poppins SemiBold headline with one upright teal accent word, DM Mono spec labels, Ink
  total block, the one orange dash, and the teal lock-up in the footer.

## 10. Voice (book §6)

Bright. Bold. Clear. Together. **Optimistic** (lead with the opportunity), **direct** (make a call and
explain it), **plain** (short sentences, zero jargon), **warm** (we, you, us).

- CTAs: "Let's grow", "Let's talk", "Start a project", "See the work".
- Instead of "Unfortunately, the campaign underperformed." → "We missed the target. Here's the fix."
- Instead of "Please do not hesitate to contact us." → "Got a question? Let's talk."
- The tagline is always "Marketing brighter together." — with the full stop.

## 11. Motion & interaction

- `animate-rise` (6px rise + fade, 250ms, ease `(0.2, 0.8, 0.2, 1)`) on headers and first content
  blocks; stagger with `[animation-delay:80ms]` at most once.
- Hover/focus transitions 150ms with the brand ease (Tailwind default is set to it).
  `prefers-reduced-motion` disables the rise.
- Visible focus ring on everything (blue `ring`, 3px, offset on buttons).

## 12. Accessibility & responsiveness

- WCAG AA in both modes (book §3.6 ratios). Body text never below 16px on mobile marketing pages.
  Status = colour + label (+ icon where space allows). Touch targets ≥ 44×44 on mobile.
- Wide grids (calendar month/week) scroll horizontally inside their panel below ~760px rather than
  crushing; rosters collapse to a two-line row on mobile. Poppins is wider than most sans faces —
  let segmented controls scroll (`overflow-x-auto`, `whitespace-nowrap`) rather than wrap.
- The review portal, client portal, and Home must be fully usable on mobile.

## 13. What to avoid (book §9.2 "before it ships")

- Orange as a button, surface, status or more than one dash per layout.
- Teal text, icons or logo on Ink.
- A third font family, a second icon set, or a second component library.
- More than two Poppins weights in a layout; caps outside eyebrows.
- Patterns or peeks on data screens; two graphics in one layout; squiggle with confetti; body text on
  a pattern; recoloured, stretched or redrawn artwork.
- Per-page status colour maps — extend `lib/status.ts`.
- Raw hex/palette colours in components. The only literal-colour exceptions are things that can't
  read CSS tokens: the favicon files, the Inertia progress-bar colour in `app.tsx`, `app.blade.php`'s
  pre-paint background, the email templates and the PDF view.
- Hand-rolled logo tiles or retyped wordmarks — use `BrandMark`.
