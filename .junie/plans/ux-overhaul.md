# UX / Journey Overhaul — North Star

> **Status:** APPROVED 2026-10-24 — all five moves (owner: "use your own judgement"); production
> chapter named **"Content"**. Building on `design-ui-ux` in thin slices.
> **Date:** 2026-10-24 · **Branch:** `design-ui-ux`
> **Scope:** workflow, journey and information architecture across all surfaces — **not** a
> re-skin. The Alima brand system (`brand-guidelines.md`, `lib/chapters.ts`, the `page.tsx`
> primitives, the palette and hero blocks) is excellent and stays. We are changing how the app is
> *organised* and how a person *moves through it*, reusing the existing visual language.

## 0. The one-line brief

The visual craft is already award-level. The gap is that the app is organised **by module, not by
the job**: there are three competing front doors, the production engine is buried inside the CRM,
and the core agency journey has no guided through-line. This plan fixes the **journey**, keeps the
**look**.

---

## 1. Design principles for this overhaul

1. **One front door.** A person opening the app should land in exactly one place that answers
   "what needs me, and what's going out?" — never choose between three.
2. **Separate *who* from *what*.** Clients (who we work for) and Work (what we're making) are
   different mental models and deserve different chapters.
3. **A spine, not a map.** The agency journey (onboard → brief → make → review → approve →
   schedule → publish → report → bill) should feel like one guided path, with the next step always
   obvious, not nine modules you assemble yourself.
4. **One way to create.** A single, context-aware "Create" with consistent results, reachable
   everywhere (⌘K + a primary button), instead of six divergent entry points.
5. **Momentum over menus.** Every screen ends by pointing at the next action. Empty states teach
   the next step. Checklists replace "figure it out."
6. **Keep the book.** Numbered chapters, running heads, `ChapterHero`, `StatStrip`, `StatusPill`,
   the peek — all retained. New destinations are added to `lib/chapters.ts`, never hard-coded.

---

## 2. Current-state journey map (what exists today)

### 2.1 Agency studio — the production spine
```
Create client ──▶ Client page (tabs: accounts · posts · media · campaigns · billing ·
   │                portal-access · ai · briefs)                     [no setup guidance]
   ├─▶ Brand Brain (clients/{c}/brand-brain)                         [separate tab]
   ├─▶ Connect accounts (clients/{c} = accounts tab, or connect-invite link)
   │
   ├─▶ Campaign ─▶ brief ─▶ plan ─▶ board ─▶ assets ─▶ approvals ─▶ results ─▶ activity
   │                 │                                                   (7-tab workspace)
   │                 └─▶ AI brief / AI planner ─▶ bulk-create posts
   │
   └─▶ Post editor (posts/{p}/edit)  ◀── reached from: client tab, Content hub,
          │                               Calendar empty slot, Campaign board, Grid tile,
          │                               Bulk import
          ├─▶ internal_review ─▶ waiting_client
          │       └─▶ Client review: token link (/review/:token) OR portal (/portal/posts)
          │              └─▶ approve / request changes
          ├─▶ scheduled ─▶ publish (Upload-Post adapter) ─▶ published / failed
          └─▶ results feed analytics (chapter 07) and campaign results
```

### 2.2 The three front doors (the core problem)
- **01 Overview** (`/dashboard`) — studio-wide: greeting hero, needs-attention count, StatStrip,
  queue, client health, live campaigns, revenue, going-out, reliability.
- **02 Needs attention** (`/home`) — the decision queue.
- **03 My work** (`/my-work`) — the signed-in person's own posts.

All three answer "where do I start?" They overlap and compete for the top of the sidebar.

### 2.3 Clients(05) is a mega-chapter
`/clients` + subnav `Posts (content hub)`, `Campaigns`, `Grids`, plus `/ai-trends`,
`/ai-post-ideas`. The actual daily *work* lives here, nested under the CRM.

### 2.4 Other surfaces (in good shape, lighter touch needed)
- **Business/creator workspace** (`/today` book): calmer, phone-first quick post, good spine.
- **Client portal** (`/portal/*`): approvals-led, standalone shell — strong.
- **Public token journeys**: `/review/:token`, `/share/campaigns/:token`, `/kit/:token`,
  `/client-invite`, `/connect`, `/portal-login` (magic link).
- **Super admin** (`/super-admin/*`): its own shorter book.

---

## 3. Proposed IA — the moves that need sign-off

> These touch `lib/chapters.ts` (the single source of truth), `app-sidebar.tsx`, every running
> head, the command palette, and the governance tests (`ModuleDocsTest`, chapter numbering). They
> are the skeleton — agree these before screens move.

### MOVE 1 — Collapse the three front doors into **one Home**
- **New `01 Home`** replaces Overview as the single entry. It carries the greeting `ChapterHero`
  (kept) and then **two segmented views**: **"The studio"** (today-wide: going out, client health,
  revenue, reliability, live campaigns) and **"Mine"** (my work: assigned, to review, due soon,
  mentioned). The **needs-attention queue is always the top band** of both views — it is the thing
  that must never be hunted for.
- `/dashboard`, `/home`, `/my-work` all resolve into this one chapter (routes kept as deep links /
  default tab selectors, so nothing 404s and existing links survive).
- **Result:** sidebar top is one confident item, not three.

### MOVE 2 — Promote **`Work`** to a top-level chapter; slim **`Clients`** to the CRM
- **New `03 Work`** (icon `Send`/`LayoutGrid`): the production hub = **Posts (content hub)**,
  **Campaigns**, **Grids**, **Calendar**, with the AI trends/ideas living here too. This is where
  the team spends the day.
- **`Clients`** becomes purely the roster + per-client CRM (brand brain, accounts, billing, portal
  access, briefs, health). Campaigns/posts/grids are still reachable *scoped to a client* from the
  client page, but their *home* is now `Work`.
- **Result:** "who we work for" and "what we're making" stop fighting for one number.

### MOVE 3 — Proposed new studio chapter order
```
01 Home            (was Overview + Needs attention + My work)
02 Calendar
03 Work            (Posts · Campaigns · Grids · AI)        ← promoted out of Clients
04 Clients         (roster · brand brain · accounts · briefs · portal access · health)
05 Invoices
06 Analytics
07 Staff
08 Settings
```
*(Numbers are identity, not status — renumbering is a `chapters.ts` + governance-test update, done
once. Business and platform books keep their own numbering.)*

### MOVE 4 — **One Create**
- A single `Create` primary action (sidebar header button + ⌘K) opens a small router:
  **New post · New campaign · New client · Import posts · Quick post (business)**. Context pre-fills
  (on a client page → that client; in a campaign → that campaign). All six legacy entry points call
  into it so results are identical.

### MOVE 5 — Guided **client setup** (kills "figure it out")
- A new client lands on the client page with a **setup checklist** (Brand Brain · Connect accounts ·
  Invite the client to the portal · Plan the first campaign *or* draft the first post), each item
  deep-linking to the step and ticking as it completes. Dismissible once done.

### Non-moves (explicitly keeping)
- The palette, logo/hero blocks, `ChapterHero`, all `page.tsx` primitives, `status.ts`, dark mode,
  the campaign 7-tab workspace structure (we improve its *within-tab* flow, not the tabs), the
  portal shell, the auth cover, error pages.

---

## 4. The flagship journey (deep build, after sign-off)

**"New client → first post live"** — the spine every other flow hangs off. Built directly in the
real Inertia/React pages on `design-ui-ux`, one thin slice at a time, checks green each slice
(`graphy-checks`), module docs synced each slice (`sync-module-docs`).

Slices (each independently shippable):
1. **Home unification + chapter restructure** (MOVE 1 + 2 + 3) — ✅ **DONE 2026-10-24.** New
   `01 Home` with a `HomeViewTabs` switcher (Studio / Needs attention / Mine); `/dashboard`,
   `/home`, `/my-work` all fold in and still resolve. Production engine promoted to `03 Content`;
   Clients slimmed to the CRM at `04`; chapters renumbered once. `lib/chapters.ts`, `app-sidebar`,
   `command-palette`, the three Home pages + the new `components/home-view-tabs.tsx`. Verified:
   `tsc`, `vp check`, frontend guards, 130 vitest tests, e2e specs unaffected, no PHP touched.
2b. **Clients ⁄ Content split** (MOVE 2 & 3) — ✅ **DONE 2026-10-24** (folded into slice 1): the
    `03 Content` chapter and slimmed `04 Clients` shipped with the renumber.
3. **Client setup checklist** (MOVE 5) — ✅ **DONE 2026-10-24.** `BuildClientPageHeaderAction` returns
   a `setup` payload; `ClientSetupChecklist` greets the studio on a new client's landing tab (Brand
   Brain → accounts → portal invite → first post), gated per ability, auto-hiding when done.
   Verified: `tsc`, `vp check`, frontend guards, `ClientTabsTest` 13/13 (2 new).
4. **One Create** (MOVE 4) — ✅ **DONE 2026-10-24.** Shared `useCreateActions` hook + an
   always-visible `CreateMenu` "New" button in the workspace header; the ⌘K Create group now reads
   the same hook (no drift). Verified: `tsc`, `vp check`, guards, 130 vitest tests.
5. **Post editor journey polish** — ✅ **DONE 2026-10-24.** The build-step stepper, `StageTrack`
   status spine, `statusGuidance` + `primaryTransitionFor` ("next step") and the review step were
   already in place from this branch's recent refactor — left as-is (excellent). The one handoff
   gap closed: a **copyable review link** (`POST /posts/{post}/review-link` →
   `CreateShareableReviewLinkAction`; `ReviewLinkRow` "Copy review link"), usable even when the
   client has no email on file. Verified: `tsc`, `vp check`, guards, 130 vitest, new
   `ShareableReviewLinkTest` 3/3.
6. **Review & approval handoff** — ✅ **DONE 2026-10-24.** The `/review/:token` page and the portal
   post page were already faithful to brand §8 (preview leads, swipe + two 56px decisions, approval
   steps) — left as-is. Closed the queue gap: `PortalPostController::show` returns a `queue`
   payload and `portal/posts/show.tsx` shows a one-tap **"Review the next"** card so approving
   leads through the backlog (brand §8). The portal list already led with "Needs your approval" +
   "Approve all". Verified: `tsc`, `vp check`, guards, `PortalPostControllerTest` 10/10 (2 new).

Then propagate the patterns (one-front-door, one-create, next-step momentum, setup checklists) to
the **business workspace** and **client portal**.

### Momentum / empty-state audit ✅ DONE 2026-10-24
Swept the primary list pages for dead-end empty states (principle 5). Finding: the app is already
overwhelmingly compliant — clients, campaigns, business posts/collections, super-admin all carry a
next action, and super-admin leads with a `ChapterHero` + spark CTA. The one real dead-end — the
**content hub** ("No posts yet" with no action) — now offers "New post" via its own picker. Grids and
read-only/analytics empty states are correctly actionless (create is client-scoped / there's nothing
to do). No further changes warranted.

### Propagation — business workspace ✅ DONE 2026-10-24
One Create already covered business (`CreateMenu` + `useCreateActions`); "one front door" is moot
(Today is the single home). Added a **brand setup checklist** on Today (connect → brand → goal →
first post) on a new shared `components/setup-checklist.tsx` — the agency client checklist was
refactored onto it too, and `BrandBrain::isFilled()` now backs the "brand" step for both.
`GetBusinessDashboardAction` returns the `setup` payload. Verified: `tsc`, `vp check`, guards, 130
vitest, `BusinessDashboardTest` 9/9, `ClientTabsTest` 13/13. (The client **portal** already leads
with "Needs your approval" + "Approve all" and now has the "Review the next" queue — propagation
there is covered by slice 6.)

---

## 5. Sequencing & guard rails

- Work in thin slices on `design-ui-ux`; each slice: build → `graphy-checks` → `sync-module-docs`
  → convention/UI reviewers.
- Respect the memory rules: PowerShell `php` (Herd 8.5); **never** `migrate:fresh` the real DB;
  clear config cache before `php artisan test`; MySQL (not Postgres); governance registries.
- Every IA change goes through `lib/chapters.ts`; never hard-code a chapter number.
- Keep every legacy route resolving (redirect or default-tab) so no existing link or bookmark dies.

---

## 6. Open decisions (for the owner)

1. **MOVE 1** — collapse Overview + Needs attention + My work into one adaptive Home? *(recommended
   yes)*
2. **MOVE 2/3** — promote `Work` and slim `Clients`, with the renumber? *(recommended yes — biggest
   single journey win)*
3. **Chapter labels** — is "Work" the right name, or "Content" / "Studio" / "Production"?
4. **Flagship scope** — confirm "new client → first post live" as the deep build, business + portal
   to follow.
